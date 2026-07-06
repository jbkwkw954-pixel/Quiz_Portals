<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$key = trim((string)($input['key'] ?? ''));

// Constant-time compare so response timing can't leak info about the key
if (!hash_equals(PORTAL_ACCESS_KEY, $key)) {
    usleep(300000); // small delay to slow down brute-force attempts
    json_response(['ok' => false, 'error' => 'Invalid key. Access denied.'], 401);
}

$_SESSION['portal_unlocked'] = true;
json_response(['ok' => true]);
