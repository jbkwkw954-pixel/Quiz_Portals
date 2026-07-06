<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$name   = trim((string)($input['name'] ?? ''));
$course = trim((string)($input['course'] ?? ''));
$email  = trim((string)($input['email'] ?? ''));

$banks = question_banks();

$errors = [];
if ($name === '') $errors['name'] = 'Full name is required.';
if (!isset($banks[$course])) $errors['course'] = 'Please select a valid course.';
if (!preg_match('/^[^\s@]+@gmail\.com$/i', $email)) $errors['email'] = 'Please enter a valid Gmail address.';

if ($errors) {
    json_response(['ok' => false, 'errors' => $errors], 422);
}

$sessionId = bin2hex(random_bytes(16));

try {
    $stmt = db()->prepare(
        'INSERT INTO exam_sessions (id, full_name, email, course, status, ip_address)
         VALUES (:id, :name, :email, :course, "in_progress", :ip)'
    );
    $stmt->execute([
        ':id' => $sessionId,
        ':name' => $name,
        ':email' => $email,
        ':course' => $course,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('register.php DB error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not create exam session'], 500);
}

// Everything the browser is allowed to know lives in the PHP session, not
// in a cookie/localStorage value the user could tamper with.
$_SESSION['candidate'] = ['name' => $name, 'email' => $email, 'course' => $course, 'courseLabel' => $banks[$course]['label']];
$_SESSION['exam_session_id'] = $sessionId;
$_SESSION['answers'] = array_fill(0, count($banks[$course]['questions']), null);

json_response(['ok' => true, 'session_id' => $sessionId, 'total_questions' => count($banks[$course]['questions'])]);
