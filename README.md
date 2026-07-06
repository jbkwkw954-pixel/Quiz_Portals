# AI-Proctored Exam Portal — Setup & Architecture

## Why three separate programs

| Layer | Runs where | Owns |
|---|---|---|
| **Front-end** (`index.php` output) | Candidate's browser | UI only. Never decides pass/fail/chances. |
| **Backend** (`*.php`) | Your web server | Credentials, question bank + correct answers, scoring, session state, DB, email. |
| **AI Engine** (`app.py`) | Candidate's own machine, local process | Camera, microphone, face embedding, gaze, stress, audio anomaly detection. |

Anything a user can see or edit via Inspect Element (HTML/CSS/JS) can never
be trusted to enforce security. That's why:
- The question bank and correct answers live only in `config.php` and are
  fetched **one at a time, answer stripped**, via `get_question.php`.
- Grading happens in `submit_quiz.php`, server-side, using the session's
  saved answers — the browser never computes or reports a score.
- `chances_left` and `locked` are only ever produced by `app.py`. When it
  hits 0, `app.py` mints an **HMAC-signed token** (`SHARED_SECRET`, never
  sent to the browser) that the browser must relay untouched to
  `block_quiz.php`, which re-verifies the signature before honoring it.
  A user editing the page's JS has no way to forge that signature.

## 1. Install & run the AI engine

```bash
cd exam-system
pip install -r requirements.txt --break-system-packages   # or use a venv
export PROCTOR_SHARED_SECRET="paste-a-long-random-string-here"
python app.py
# -> AI engine listening on http://127.0.0.1:5000
```

Notes:
- `face_recognition` needs `dlib` (needs a C++ build toolchain / cmake).
  If that's painful on your platform, swap it for `deepface` or `insightface`
  — the calibration/embedding-compare code is isolated in `camera_worker()`.
- Only one process can normally hold a webcam open at a time. That's why
  the browser does **not** call `getUserMedia()` for the proctoring camera;
  it polls `/api/frame` (an annotated JPEG snapshot, ~1-2 fps) instead.
- `pyttsx3` uses your OS's built-in TTS voice (SAPI5 on Windows, NSSpeechSynthesizer
  on macOS, espeak on Linux) to literally speak "Warning! Warning! Warning!"
  and other prompts out loud through the speakers.

## 2. Set up the database

```bash
mysql -u root -p < schema.sql
```

Create an app user with only the privileges it needs on `exam_portal`,
and set `EXAM_DB_USER` / `EXAM_DB_PASS` as environment variables for PHP
(see `config.php`).

## 3. Configure PHP

Set these environment variables for the PHP process (e.g. in your
webserver's vhost config or `.htaccess`/`php.ini`):

```
EXAM_DB_HOST=127.0.0.1
EXAM_DB_NAME=exam_portal
EXAM_DB_USER=exam_app
EXAM_DB_PASS=your-db-password
PROCTOR_SHARED_SECRET=same-long-random-string-as-app.py
PROCTOR_ENGINE_URL=http://127.0.0.1:5000
PORTAL_ACCESS_KEY=whatever-key-you-give-candidates
EXAM_ADMIN_EMAIL=admin@yourdomain.com
```

Serve `index.php` behind Apache/Nginx + PHP-FPM as usual. **Serve it over
HTTPS in production** — then also uncomment `session.cookie_secure` in
`config.php`.

## 4. File map

```
app.py              AI engine: calibration, face count, gaze, stress, audio anomaly, TTS
config.php           DB connection, question bank (with answers), shared secret, session bootstrap
login.php            Validates the access key server-side
register.php          Creates a session row; strips nothing sensitive to the client except the answer key
get_question.php      Serves ONE question at a time, answer key stripped
save_answer.php       Persists a candidate's choice into the PHP session
submit_quiz.php        Grades server-side using the saved answers; never trusts a client-supplied score
block_quiz.php         Verifies the AI engine's signed lock token; marks the session "malpractice"
submit_feedback.php    Saves feedback + emails Administration one consolidated report
schema.sql             MySQL schema
index.php              The actual page candidates see (HTML/CSS/JS + PHP)
```

## 5. Known limitations to harden further before real-world use

- `app.py` is single-session by design (one engine per candidate machine).
  For a lab of many simultaneous candidates, run one engine instance per
  machine, each pointed at its own `session_id`.
- The server-side exam timer is enforced client-side for UX (`index.php`'s
  countdown) but not independently re-checked in `submit_quiz.php` — add a
  `started_at` timestamp column and check elapsed time server-side too.
- `mail()` requires a configured MTA; swap for PHPMailer/SMTP in production.
- Add rate limiting to `login.php` beyond the simple `usleep()` delay.
