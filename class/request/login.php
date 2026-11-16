<?php
/**
 * POST /request/login.php
 * Body: {"username": "...", "password": "..."}
 * Response: {"success": true, "user": {id, username, role, created_at}}
 */
require_once __DIR__ . '/../config/Api.php';

Api::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Api::respond(405, ['success' => false, 'message' => 'Only POST is allowed.']);
}

$in       = Api::input();
$username = trim((string)($in['username'] ?? ''));
$password = (string)($in['password'] ?? '');

if ($username === '' || $password === '') {
    Api::respond(400, ['success' => false, 'message' => 'Provide username and password.']);
}

$user = (new User(Api::db()))->authenticate($username, $password);
if (!$user) {
    Api::respond(401, ['success' => false, 'message' => 'Invalid username or password.']);
}

Api::respond(200, ['success' => true, 'message' => 'Login successful.', 'user' => $user]);
?>