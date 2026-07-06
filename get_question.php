<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$candidate = require_candidate_session();
$banks = question_banks();
$questions = $banks[$candidate['course']]['questions'];

$index = isset($_GET['index']) ? (int)$_GET['index'] : 0;
if ($index < 0 || $index >= count($questions)) {
    json_response(['ok' => false, 'error' => 'Invalid question index'], 400);
}

$q = $questions[$index];

// Strip the correct-answer key — this is the whole point of serving
// questions from PHP instead of embedding the answer bank in JS.
json_response([
    'ok' => true,
    'index' => $index,
    'total' => count($questions),
    'course_label' => $banks[$candidate['course']]['label'],
    'question' => $q['q'],
    'options' => $q['opts'],
    'selected' => $_SESSION['answers'][$index] ?? null,
]);
