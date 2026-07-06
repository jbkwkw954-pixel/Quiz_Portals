<?php
/**
 * block_quiz.php
 *
 * Called by the browser the instant the AI engine (app.py) reports
 * chances_left == 0 / locked == true. The browser only relays an opaque
 * signed token — it never decides on its own that the candidate failed.
 *
 * Token format (produced by app.py's sign_lock_token()):
 *   base64(json_payload) + "." + hex_hmac_sha256(json_payload, SHARED_SECRET)
 *
 * If the signature doesn't verify, the request is rejected outright — a
 * candidate editing the page's JS has no way to produce a valid signature
 * because SHARED_SECRET never touches the browser.
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$candidate = require_candidate_session();
$sessionId = $_SESSION['exam_session_id'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$token = (string)($input['lock_token'] ?? '');

if ($token === '' || strpos($token, '.') === false) {
    json_response(['ok' => false, 'error' => 'Malformed token'], 400);
}

[$b64Payload, $sig] = explode('.', $token, 2);
$rawPayload = base64_decode(strtr($b64Payload, '-_', '+/'));
if ($rawPayload === false) {
    json_response(['ok' => false, 'error' => 'Malformed payload'], 400);
}

$expectedSig = hash_hmac('sha256', $rawPayload, PROCTOR_SHARED_SECRET);
if (!hash_equals($expectedSig, $sig)) {
    error_log("block_quiz.php: INVALID token signature for session {$sessionId} — possible tampering attempt");
    json_response(['ok' => false, 'error' => 'Invalid signature'], 403);
}

$payload = json_decode($rawPayload, true);
if (!$payload || ($payload['session_id'] ?? null) !== $sessionId) {
    json_response(['ok' => false, 'error' => 'Token does not match this session'], 403);
}

// Optional replay-window check: reject tokens older than 60s
if (abs(time() - (int)($payload['ts'] ?? 0)) > 60) {
    json_response(['ok' => false, 'error' => 'Token expired'], 403);
}

$reason = substr((string)($payload['reason'] ?? 'Malpractice detected'), 0, 250);

try {
    $stmt = db()->prepare(
        "UPDATE exam_sessions
         SET status = 'malpractice', lock_reason = :reason
         WHERE id = :id AND status = 'in_progress'"
    );
    $stmt->execute([':reason' => $reason, ':id' => $sessionId]);

    $log = db()->prepare('INSERT INTO violation_log (session_id, reason) VALUES (:id, :reason)');
    $log->execute([':id' => $sessionId, ':reason' => $reason]);
} catch (Throwable $e) {
    error_log('block_quiz.php DB error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not update session'], 500);
}

// Force-close the portal server-side: destroy the PHP session so the
// candidate cannot navigate back into the quiz screens even if they keep
// the tab open.
$_SESSION['status'] = 'locked';
session_regenerate_id(true);

json_response(['ok' => true, 'status' => 'malpractice', 'reason' => $reason]);
