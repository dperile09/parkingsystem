<?php
/**
 * Api
 * Small helpers for all REST endpoints:
 *  - CORS + JSON headers
 *  - JSON input/output
 *  - Authentication (stateless): every request has headers
 *    X-Username and X-Password. Password comparison is in PLAIN TEXT.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../models/User.php';

class Api
{
    /** Call at the beginning of every endpoint. */
    public static function init(): void
    {
        date_default_timezone_set('Asia/Manila');
        ini_set('display_errors', '0'); // to keep JSON clean

        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Accept, X-Username, X-Password, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

        // Preflight request (Flutter Web)
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Any uncaught error -> JSON 500
        set_exception_handler(function (Throwable $e) {
            self::respond(500, ['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        });
    }

    public static function db(): PDO
    {
        return (new Database())->getConnection();
    }

    /** Send a JSON response and terminate the script. */
    public static function respond(int $code, array $data): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Read the JSON body of the request. */
    public static function input(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw === false ? '' : $raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Verify user and role.
     * @param string[] $allowedRoles Empty = any valid user.
     * @return array The user (id, username, role, created_at)
     */
    public static function authenticate(array $allowedRoles = []): array
    {
        $username = trim($_SERVER['HTTP_X_USERNAME'] ?? '');
        $password = (string)($_SERVER['HTTP_X_PASSWORD'] ?? '');

        if ($username === '' || $password === '') {
            self::respond(401, ['success' => false, 'message' => 'Login required.']);
        }

        $user = (new User(self::db()))->authenticate($username, $password);
        if (!$user) {
            self::respond(401, ['success' => false, 'message' => 'Invalid username or password.']);
        }
        if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
            self::respond(403, ['success' => false, 'message' => 'You do not have access to this action.']);
        }
        return $user;
    }
}