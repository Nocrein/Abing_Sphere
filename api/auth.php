<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$email = trim($body['email'] ?? '');
$password = trim($body['password'] ?? '');

if (!$email || !$password) {
    errorResponse('Email and password required');
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    errorResponse('Invalid credentials', 401);
}

$token = generateToken($user['id']);
jsonResponse([
    'token' => $token,
    'user' => ['id' => $user['id'], 'email' => $user['email']]
]);
