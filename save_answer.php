<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$candidate = require_candidate_session();
$banks = question_banks();
$total = count($banks[$candidate['course']]['questions']);

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$index = (int)($input['index'] ?? -1);
$choice = $input['choice'] ?? null;

if ($index < 0 || $index >= $total || !is_int($choice) || $choice < 0 || $choice > 3) {
    json_response(['ok' => false, 'error' => 'Invalid answer payload'], 400);
}

if (!isset($_SESSION['answers']) || $_SESSION['status'] ?? null === 'locked') {
    json_response(['ok' => false, 'error' => 'No active session'], 401);
}

$_SESSION['answers'][$index] = $choice;
json_response(['ok' => true]);
