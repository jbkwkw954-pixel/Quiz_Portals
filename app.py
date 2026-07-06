"""
================================================================================
 AI PROCTOR ENGINE  (app.py)
================================================================================
Runs LOCALLY on the candidate's machine, in the background, for the duration
of the exam. It owns the webcam and microphone directly (it does NOT receive
video/audio over the network from the browser) and exposes a small local
HTTP API on http://127.0.0.1:5000 that the exam page (served by PHP) polls.

Why this design:
- The browser (index.php + JS) can be opened in DevTools and edited freely.
  Nothing that decides pass/fail or deducts chances may live there.
- This engine is the single source of truth for "chances_left" and "locked".
  The browser only DISPLAYS what this engine reports; it cannot change it.
- When the 5th chance is lost, this engine signs a tamper-proof token (HMAC)
  that only the PHP backend can verify (shared secret never sent to JS).
  The browser merely relays that opaque token to block_quiz.php.

Install:
    pip install flask flask-cors opencv-python mediapipe sounddevice numpy
    pip install face_recognition pyttsx3            # or deepface / insightface
    (face_recognition needs dlib + cmake; on Windows use a prebuilt wheel)

Run:
    python app.py
    -> serves http://127.0.0.1:5000

Config:
    Set PROCTOR_SHARED_SECRET to the SAME value as $PROCTOR_SHARED_SECRET
    in config.php. Never commit real secrets — load from an env var in prod.
================================================================================
"""

import os
import time
import hmac
import json
import base64
import hashlib
import logging
import threading
import collections

import cv2
import numpy as np
import sounddevice as sd
from flask import Flask, jsonify, request
from flask_cors import CORS

# ---- optional heavy deps guarded so the file still runs / degrades if missing
try:
    import mediapipe as mp
    MP_OK = True
except Exception:
    MP_OK = False

try:
    import face_recognition
    FACE_REC_OK = True
except Exception:
    FACE_REC_OK = False

try:
    import pyttsx3
    TTS_OK = True
except Exception:
    TTS_OK = False

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("proctor")

# =============================================================================
# CONFIG
# =============================================================================
SHARED_SECRET = os.environ.get("PROCTOR_SHARED_SECRET", "CHANGE_ME_TO_A_LONG_RANDOM_SECRET")
CAMERA_INDEX = int(os.environ.get("PROCTOR_CAMERA_INDEX", "0"))
AUDIO_SAMPLE_RATE = 16000
AUDIO_BLOCK_SEC = 0.25            # analyze mic in 250ms chunks
CALIBRATION_SECONDS = 10
FACE_MATCH_TOLERANCE = 0.55       # face_recognition distance threshold
GAZE_AWAY_FRAMES_FOR_WARNING = 18  # ~ frames of sustained look-away before soft warning
STRESS_FRAMES_FOR_NOTICE = 40
MAX_CHANCES = 5

# audio anomaly must persist this long (seconds) above threshold before it
# counts as a *violation* (deducts a chance). Short blips (a single cough,
# a chair creak) are ignored — this is the "hyper-vigilant but fair" rule.
AUDIO_VIOLATION_SUSTAIN_SEC = 1.2
AUDIO_ANOMALY_MULTIPLIER = 2.6   # live RMS must exceed baseline*this to count

VIOLATION_COOLDOWN_SEC = 3.0     # min gap between two chance deductions

app = Flask(__name__)
CORS(app)  # local engine only talks to the exam origin; lock this down in prod
           # with CORS(app, origins=["https://your-exam-domain.example"])

# =============================================================================
# SHARED STATE  (single active exam session per engine instance, by design —
# this engine is meant to run per-candidate-machine, not multi-tenant)
# =============================================================================
state_lock = threading.RLock()

def fresh_state():
    return {
        "session_id": None,
        "running": False,
        "calibrated": False,
        "calibration_progress": 0,       # 0-100
        "calibration_stage": "idle",     # idle|face|audio|done
        "baseline_embedding": None,      # numpy array (not serialized to client)
        "audio_baseline_rms": None,
        "chances_left": MAX_CHANCES,
        "locked": False,
        "lock_reason": None,
        "lock_token": None,              # signed HMAC token for block_quiz.php
        "last_alert": None,              # {"type","message","ts"}
        "gaze_alert": None,              # transient "Don't cheat!" popup
        "stress_alert": None,            # transient "Relax, you can do it!" popup
        "events": collections.deque(maxlen=200),
        "latest_jpeg": None,              # bytes — annotated preview frame for the browser
    }

STATE = fresh_state()

_camera_thread = None
_audio_thread = None
_stop_flag = threading.Event()


def log_event(kind, message):
    with state_lock:
        STATE["events"].append({"type": kind, "message": message, "ts": time.time()})
    log.info(f"[{kind}] {message}")


def sign_lock_token(session_id, reason):
    """
    Produces an opaque, tamper-proof token that JS relays verbatim to
    block_quiz.php. The PHP side re-computes the same HMAC with the shared
    secret and rejects anything that doesn't match — so a user editing the
    JS/DOM cannot forge a "still passing" state, and cannot forge a fake
    lock either, because they never see SHARED_SECRET.
    """
    payload = {
        "session_id": session_id,
        "reason": reason,
        "chances_left": 0,
        "ts": int(time.time()),
    }
    raw = json.dumps(payload, separators=(",", ":"), sort_keys=True).encode()
    sig = hmac.new(SHARED_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    token = base64.urlsafe_b64encode(raw).decode() + "." + sig
    return token


def speak_async(text, repeat=1, pause=0.15):
    if not TTS_OK:
        log.warning(f"TTS unavailable, would have said: {text!r} x{repeat}")
        return

    def _run():
        try:
            engine = pyttsx3.init()
            for _ in range(repeat):
                engine.say(text)
                engine.runAndWait()
                time.sleep(pause)
        except Exception as e:
            log.warning(f"TTS error: {e}")

    threading.Thread(target=_run, daemon=True).start()


def deduct_chance(reason):
    """The single, thread-safe choke point for losing a chance. Runs
    instantly inside whichever detector thread found the violation — there
    is no polling delay between detection and deduction."""
    with state_lock:
        if STATE["locked"]:
            return
        now = time.time()
        last = STATE.get("_last_violation_ts", 0)
        if now - last < VIOLATION_COOLDOWN_SEC:
            return
        STATE["_last_violation_ts"] = now

        STATE["chances_left"] = max(0, STATE["chances_left"] - 1)
        STATE["last_alert"] = {"type": "violation", "message": reason, "ts": now}
        log_event("VIOLATION", reason)

        if STATE["chances_left"] <= 0 and not STATE["locked"]:
            STATE["locked"] = True
            STATE["lock_reason"] = reason
            STATE["lock_token"] = sign_lock_token(STATE["session_id"], reason)
            log_event("LOCKED", f"Session locked: {reason}")


# =============================================================================
# CAMERA WORKER  — face count / identity / gaze / stress
# =============================================================================
def camera_worker():
    cap = cv2.VideoCapture(CAMERA_INDEX)
    if not cap.isOpened():
        log_event("ERROR", "Could not open camera")
        return

    face_mesh = None
    if MP_OK:
        face_mesh = mp.solutions.face_mesh.FaceMesh(
            max_num_faces=3, refine_landmarks=True,
            min_detection_confidence=0.6, min_tracking_confidence=0.5
        )

    gaze_away_streak = 0
    stress_streak = 0
    calibration_frames = []
    calibration_start = None

    # left/right iris landmark indices (MediaPipe FaceMesh refine_landmarks)
    LEFT_IRIS = [468, 469, 470, 471]
    RIGHT_IRIS = [473, 474, 475, 476]
    LEFT_EYE_CORNERS = [33, 133]
    RIGHT_EYE_CORNERS = [362, 263]
    MOUTH_TOP, MOUTH_BOTTOM = 13, 14
    BROW_L, BROW_R = 105, 334

    while not _stop_flag.is_set():
        ok, frame = cap.read()
        if not ok:
            time.sleep(0.05)
            continue

        with state_lock:
            running = STATE["running"]
            calibrated = STATE["calibrated"]
            stage = STATE["calibration_stage"]
            locked = STATE["locked"]

        if not running:
            time.sleep(0.1)
            continue

        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)

        # ---------------- CALIBRATION: capture face embedding for 10s -------
        if stage == "face":
            if calibration_start is None:
                calibration_start = time.time()
                calibration_frames = []
            elapsed = time.time() - calibration_start
            pct = min(100, int((elapsed / CALIBRATION_SECONDS) * 100))
            with state_lock:
                STATE["calibration_progress"] = pct

            if FACE_REC_OK:
                locs = face_recognition.face_locations(rgb, model="hog")
                if len(locs) == 1:
                    encs = face_recognition.face_encodings(rgb, locs)
                    if encs:
                        calibration_frames.append(encs[0])

            if elapsed >= CALIBRATION_SECONDS:
                baseline = None
                if calibration_frames:
                    baseline = np.mean(np.array(calibration_frames), axis=0)
                with state_lock:
                    STATE["baseline_embedding"] = baseline
                    STATE["calibration_stage"] = "audio"
                    STATE["calibration_progress"] = 0
                calibration_start = None
                log_event("CALIBRATION", "Face embedding captured" if baseline is not None
                           else "Face embedding FAILED — no stable single face seen")
            continue  # don't run live monitoring while calibrating

        if stage != "done" or locked:
            time.sleep(0.05)
            continue

        # ---------------- LIVE MONITORING ------------------------------------
        num_faces_seen = 0
        primary_landmarks = None

        if MP_OK and face_mesh is not None:
            result = face_mesh.process(rgb)
            if result.multi_face_landmarks:
                num_faces_seen = len(result.multi_face_landmarks)
                primary_landmarks = result.multi_face_landmarks[0]
        elif FACE_REC_OK:
            locs = face_recognition.face_locations(rgb, model="hog")
            num_faces_seen = len(locs)

        # Rule: a second face / silhouette in frame => INSTANT deduction
        if num_faces_seen >= 2:
            deduct_chance("A second face / person was detected in the camera frame.")

        if num_faces_seen == 0:
            # handled softly — grace period is enforced client-side via the
            # status feed's "no_face_since" pattern is intentionally NOT done
            # here; camera dropout alone should not instantly fail someone,
            # it's reported as an alert, not a violation.
            with state_lock:
                STATE["last_alert"] = {"type": "no_face", "message": "Face not visible", "ts": time.time()}

        # Identity check (optional, logged as event — does not deduct by
        # itself since lighting changes can shift embeddings; combine with
        # your own policy if you want identity mismatch to be punitive)
        if num_faces_seen == 1 and FACE_REC_OK and STATE.get("baseline_embedding") is not None:
            locs = face_recognition.face_locations(rgb, model="hog")
            if locs:
                encs = face_recognition.face_encodings(rgb, locs)
                if encs:
                    dist = np.linalg.norm(encs[0] - STATE["baseline_embedding"])
                    if dist > FACE_MATCH_TOLERANCE:
                        log_event("IDENTITY_WARN", f"Face embedding drift {dist:.2f} > tolerance")

        # Gaze tracking (MediaPipe iris landmarks)
        if primary_landmarks is not None:
            h, w = frame.shape[:2]
            pts = primary_landmarks.landmark

            def pt(i):
                return np.array([pts[i].x * w, pts[i].y * h])

            try:
                l_corner_a, l_corner_b = pt(LEFT_EYE_CORNERS[0]), pt(LEFT_EYE_CORNERS[1])
                r_corner_a, r_corner_b = pt(RIGHT_EYE_CORNERS[0]), pt(RIGHT_EYE_CORNERS[1])
                l_iris = np.mean([pt(i) for i in LEFT_IRIS], axis=0)
                r_iris = np.mean([pt(i) for i in RIGHT_IRIS], axis=0)

                l_ratio = (l_iris[0] - l_corner_a[0]) / (l_corner_b[0] - l_corner_a[0] + 1e-6)
                r_ratio = (r_iris[0] - r_corner_a[0]) / (r_corner_b[0] - r_corner_a[0] + 1e-6)
                horiz = (l_ratio + r_ratio) / 2.0  # ~0.5 = centered

                looking_away = horiz < 0.32 or horiz > 0.68
                if looking_away:
                    gaze_away_streak += 1
                else:
                    gaze_away_streak = max(0, gaze_away_streak - 2)

                if gaze_away_streak >= GAZE_AWAY_FRAMES_FOR_WARNING:
                    with state_lock:
                        STATE["gaze_alert"] = {"message": "Don't cheat!", "ts": time.time()}
                    log_event("GAZE", "Sustained look-away detected — soft warning issued")
                    gaze_away_streak = 0  # reset so it doesn't spam every frame

                # ---- lightweight stress/fatigue heuristic (mouth+brow) ----
                mouth_open = abs(pt(MOUTH_TOP)[1] - pt(MOUTH_BOTTOM)[1])
                brow_span = abs(pt(BROW_L)[0] - pt(BROW_R)[0])
                tension_ratio = mouth_open / (brow_span + 1e-6)
                if tension_ratio > 0.16:
                    stress_streak += 1
                else:
                    stress_streak = max(0, stress_streak - 2)

                if stress_streak >= STRESS_FRAMES_FOR_NOTICE:
                    with state_lock:
                        STATE["stress_alert"] = {"message": "Relax, you can do it!", "ts": time.time()}
                    log_event("STRESS", "Stress/fatigue signature detected — encouragement issued")
                    stress_streak = 0
            except Exception:
                pass

        # ---- publish a lightweight annotated preview for the browser to
        # poll (the browser must NOT open its own getUserMedia — most
        # webcams/drivers only allow one active reader at a time) ----
        preview = frame.copy()
        label = f"Faces: {num_faces_seen}"
        color = (0, 255, 65) if num_faces_seen == 1 else (0, 0, 255)
        cv2.putText(preview, label, (12, 28), cv2.FONT_HERSHEY_SIMPLEX, 0.7, color, 2)
        ok_enc, buf = cv2.imencode('.jpg', preview, [cv2.IMWRITE_JPEG_QUALITY, 60])
        if ok_enc:
            with state_lock:
                STATE["latest_jpeg"] = buf.tobytes()

        time.sleep(0.03)  # ~30fps cap

    cap.release()
    if face_mesh is not None:
        face_mesh.close()


# =============================================================================
# AUDIO WORKER — ambient calibration + live 2m-radius anomaly detection
# =============================================================================
def rms_dbfs(block):
    rms = np.sqrt(np.mean(np.square(block.astype(np.float64))) + 1e-12)
    dbfs = 20 * np.log10(rms + 1e-12)
    return rms, dbfs


def audio_worker():
    block_frames = int(AUDIO_SAMPLE_RATE * AUDIO_BLOCK_SEC)
    calib_samples = []
    calib_start = None
    anomaly_since = None

    def callback(indata, frames, time_info, status):
        nonlocal calib_samples, calib_start, anomaly_since

        with state_lock:
            running = STATE["running"]
            stage = STATE["calibration_stage"]
            locked = STATE["locked"]
            baseline = STATE["audio_baseline_rms"]

        if not running:
            return

        mono = indata[:, 0] if indata.ndim > 1 else indata
        rms, dbfs = rms_dbfs(mono)

        if stage == "audio":
            if calib_start is None:
                calib_start = time.time()
                calib_samples = []
            calib_samples.append(rms)
            elapsed = time.time() - calib_start
            pct = min(100, int((elapsed / CALIBRATION_SECONDS) * 100))
            with state_lock:
                STATE["calibration_progress"] = pct

            if elapsed >= CALIBRATION_SECONDS:
                baseline_rms = float(np.mean(calib_samples)) if calib_samples else 0.002
                # smart adaptive floor: never trust an ultra-quiet baseline,
                # keep a sane minimum so the very first live sample can't
                # immediately look like an "anomaly"
                baseline_rms = max(baseline_rms, 0.0015)
                with state_lock:
                    STATE["audio_baseline_rms"] = baseline_rms
                    STATE["calibration_stage"] = "done"
                    STATE["calibration_progress"] = 100
                    STATE["calibrated"] = True
                calib_start = None
                log_event("CALIBRATION", f"Ambient audio baseline RMS={baseline_rms:.5f}")
            return

        if stage != "done" or locked or baseline is None:
            return

        threshold = baseline * AUDIO_ANOMALY_MULTIPLIER
        now = time.time()

        if rms > threshold:
            if anomaly_since is None:
                anomaly_since = now
            sustained = now - anomaly_since
            if sustained >= AUDIO_VIOLATION_SUSTAIN_SEC:
                speak_async("Warning! Warning! Warning!", repeat=1)  # engine already loops phrase once per call; see note below
                # Broadcast the literal 3x warning as required:
                speak_async("Warning!", repeat=3, pause=0.25)
                deduct_chance("An unrecognized voice / disruptive sound was detected near the candidate.")
                anomaly_since = None  # reset window after acting
        else:
            anomaly_since = None

    with sd.InputStream(channels=1, samplerate=AUDIO_SAMPLE_RATE,
                         blocksize=block_frames, callback=callback):
        while not _stop_flag.is_set():
            time.sleep(0.1)


# =============================================================================
# HTTP API
# =============================================================================
def require_running_or_start():
    with state_lock:
        return STATE["running"]


@app.route("/api/health", methods=["GET"])
def health():
    return jsonify({"ok": True, "mediapipe": MP_OK, "face_recognition": FACE_REC_OK, "tts": TTS_OK})


@app.route("/api/start-session", methods=["POST"])
def start_session():
    """Called once the candidate reaches the calibration screen. Resets all
    engine state for a brand new attempt and kicks off camera + audio
    calibration threads."""
    global _camera_thread, _audio_thread, _stop_flag

    data = request.get_json(force=True, silent=True) or {}
    session_id = data.get("session_id")
    if not session_id:
        return jsonify({"ok": False, "error": "session_id required"}), 400

    # stop any previous run cleanly first
    _stop_flag.set()
    time.sleep(0.2)
    _stop_flag = threading.Event()

    with state_lock:
        global STATE
        STATE = fresh_state()
        STATE["session_id"] = session_id
        STATE["running"] = True
        STATE["calibration_stage"] = "face"

    _camera_thread = threading.Thread(target=camera_worker, daemon=True)
    _audio_thread = threading.Thread(target=audio_worker, daemon=True)
    _camera_thread.start()
    _audio_thread.start()

    log_event("SESSION", f"New session started: {session_id}")
    return jsonify({"ok": True})


@app.route("/api/status", methods=["GET"])
def status():
    """Polled by the browser every ~500ms. This is a READ-ONLY view — the
    browser cannot write chances_left/locked through this or any endpoint."""
    with state_lock:
        gaze = STATE["gaze_alert"]
        stress = STATE["stress_alert"]
        # transient alerts are cleared after being read once, so the UI
        # doesn't re-trigger the same popup on every poll
        STATE["gaze_alert"] = None
        STATE["stress_alert"] = None

        return jsonify({
            "ok": True,
            "calibrated": STATE["calibrated"],
            "calibration_stage": STATE["calibration_stage"],
            "calibration_progress": STATE["calibration_progress"],
            "chances_left": STATE["chances_left"],
            "locked": STATE["locked"],
            "lock_reason": STATE["lock_reason"],
            "lock_token": STATE["lock_token"],
            "last_alert": STATE["last_alert"],
            "gaze_alert": gaze,
            "stress_alert": stress,
        })


@app.route("/api/frame", methods=["GET"])
def frame():
    """Returns the latest annotated JPEG so the browser can show the
    candidate a live-ish self-view without opening a second, conflicting
    handle on the webcam. Poll this at ~4-6 fps from an <img> tag."""
    from flask import Response
    with state_lock:
        jpeg = STATE["latest_jpeg"]
    if not jpeg:
        return Response(status=204)
    return Response(jpeg, mimetype="image/jpeg")


@app.route("/api/stop-session", methods=["POST"])
def stop_session():
    _stop_flag.set()
    with state_lock:
        STATE["running"] = False
    log_event("SESSION", "Session stopped")
    return jsonify({"ok": True})


if __name__ == "__main__":
    if SHARED_SECRET.startswith("CHANGE_ME"):
        log.warning("PROCTOR_SHARED_SECRET is still the placeholder value — set a real secret before going live!")
    app.run(host="127.0.0.1", port=5000, debug=False, threaded=True)
