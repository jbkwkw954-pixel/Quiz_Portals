<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$candidate = require_candidate_session();
$sessionId = $_SESSION['exam_session_id'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$feedback = [
    'test_experience'      => (int)($input['q1'] ?? 0),
    'course_environment'   => (int)($input['q2'] ?? 0),
    'equipment'            => (int)($input['q3'] ?? 0),
    'instructor'           => (int)($input['q4'] ?? 0),
];

try {
    $stmt = db()->prepare('UPDATE exam_sessions SET feedback_json = :fb WHERE id = :id');
    $stmt->execute([':fb' => json_encode($feedback), ':id' => $sessionId]);

    $row = db()->prepare('SELECT * FROM exam_sessions WHERE id = :id');
    $row->execute([':id' => $sessionId]);
    $session = $row->fetch();
} catch (Throwable $e) {
    error_log('submit_feedback.php DB error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not save feedback'], 500);
}

// ---- Send one consolidated email to Administration ----
// Swap this for PHPMailer/SMTP in production; PHP's mail() requires a
// configured MTA on the server. Kept minimal here to stay dependency-free.
$adminEmail = getenv('EXAM_ADMIN_EMAIL') ?: 'admin@example.com';
$subject = 'Exam Result: ' . $session['full_name'] . ' — ' . strtoupper($session['status']);
$body = "Candidate: {$session['full_name']}\n"
      . "Email: {$session['email']}\n"
      . "Course: {$session['course']}\n"
      . "Score: {$session['score_correct']}/{$session['score_total']}\n"
      . "Result: {$session['status']}\n"
      . "Lock reason: " . ($session['lock_reason'] ?? '-') . "\n"
      . "Feedback: " . $session['feedback_json'] . "\n";

$headers = "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'exam-portal.local') . "\r\n";
$mailSent = @mail($adminEmail, $subject, $body, $headers);

json_response(['ok' => true, 'emailed' => $mailSent]);
