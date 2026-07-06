<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$candidate = require_candidate_session();
$sessionId = $_SESSION['exam_session_id'];

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$reason = in_array($input['reason'] ?? 'manual', ['manual', 'time'], true) ? $input['reason'] : 'manual';

$banks = question_banks();
$questions = $banks[$candidate['course']]['questions'];
$answers = $_SESSION['answers'] ?? [];

$correct = 0;
foreach ($questions as $i => $q) {
    if (($answers[$i] ?? null) === $q['correct']) $correct++;
}
$total = count($questions);
$passed = $correct >= 7; // pass mark, server-side only

try {
    $stmt = db()->prepare(
        "UPDATE exam_sessions
         SET answers_json = :answers, score_correct = :c, score_total = :t,
             status = :status
         WHERE id = :id AND status = 'in_progress'"
    );
    $stmt->execute([
        ':answers' => json_encode($answers),
        ':c' => $correct,
        ':t' => $total,
        ':status' => $passed ? 'passed' : 'failed',
        ':id' => $sessionId,
    ]);
} catch (Throwable $e) {
    error_log('submit_quiz.php DB error: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Could not save result'], 500);
}

json_response([
    'ok' => true,
    'correct' => $correct,
    'total' => $total,
    'percent' => (int)round(($correct / max(1, $total)) * 100),
    'passed' => $passed,
    'reason' => $reason,
]);
