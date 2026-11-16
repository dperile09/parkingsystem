<?php
/**
 * /request/manage_operators.php   (ADMIN only)[cite: 20]
 *  GET                                  -> list of operators[cite: 20]
 *  POST   {"username","password"}       -> create an operator[cite: 20]
 *  PUT    {"id","username","password?"} -> update (blank password = no change)[cite: 20]
 *  DELETE ?id=3                         -> delete operator[cite: 20]
 * No other roles can be created (only admin and operator are valid in the system).[cite: 20]
 */
require_once __DIR__ . '/../config/Api.php';

Api::init();

Api::authenticate(['admin']);
$method = $_SERVER['REQUEST_METHOD'];
$users  = new User(Api::db());

try {
    switch ($method) {
        case 'GET':
            Api::respond(200, ['success' => true, 'operators' => $users->getAllByRole('operator')]);
            break;

        case 'POST':
            $in       = Api::input();
            $username = trim((string)($in['username'] ?? ''));
            $password = (string)($in['password'] ?? '');
            if (strlen($username) < 3 || strlen($username) > 50 || strlen($password) < 4 || strlen($password) > 100) {
                Api::respond(400, ['success' => false, 'message' => 'Username: 3-50 chars, Password: 4-100 chars.']);
            }
            if ($users->usernameExists($username)) {
                Api::respond(409, ['success' => false, 'message' => 'Username already exists.']);
            }
            $id = $users->create($username, $password, 'operator'); // always 'operator'[cite: 20]
            Api::respond(201, ['success' => true, 'message' => 'Operator created.', 'id' => $id]);
            break;

        case 'PUT':
            $in       = Api::input();
            $id       = (int)($in['id'] ?? 0);
            $username = trim((string)($in['username'] ?? ''));
            $password = isset($in['password']) ? (string)$in['password'] : null;

            $target = $users->getById($id);
            if (!$target || $target['role'] !== 'operator') {
                Api::respond(404, ['success' => false, 'message' => 'Operator not found.']);
            }
            if (strlen($username) < 3 || strlen($username) > 50) {
                Api::respond(400, ['success' => false, 'message' => 'Username: 3-50 chars.']);
            }
            if ($password !== null && $password !== '' && (strlen($password) < 4 || strlen($password) > 100)) {
                Api::respond(400, ['success' => false, 'message' => 'Password: 4-100 chars.']);
            }
            if ($users->usernameExists($username, $id)) {
                Api::respond(409, ['success' => false, 'message' => 'Username already exists.']);
            }
            $users->updateOperator($id, $username, $password);
            Api::respond(200, ['success' => true, 'message' => 'Operator updated.']);
            break;

        case 'DELETE':
            $in = Api::input();
            $id = (int)($_GET['id'] ?? ($in['id'] ?? 0));
            if (!$users->deleteOperator($id)) {
                Api::respond(404, ['success' => false, 'message' => 'Operator not found.']);
            }
            Api::respond(200, ['success' => true, 'message' => 'Operator deleted.']);
            break;

        default:
            Api::respond(405, ['success' => false, 'message' => 'Method not supported.']);
    }
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        Api::respond(409, ['success' => false, 'message' => 'Cannot delete: this operator has parking records.']);
    }
    throw $e;
}
?>