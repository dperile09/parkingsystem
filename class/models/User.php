<?php
/**
 * User model - CRUD for the `users` table.
 * Password is in PLAIN TEXT (no hashing) as per requirement.
 * Valid roles: 'admin' and 'operator' only.
 */
class User
{
    public const ROLES = ['admin', 'operator'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Login check. Returns user (without password) or null. */
    public function authenticate(string $username, string $password): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, username, password, role, created_at FROM users WHERE username = :username LIMIT 1"
        );
        $st->execute([':username' => $username]);
        $row = $st->fetch();

        // PLAIN TEXT comparison (case-sensitive)
        if (!$row || !hash_equals((string)$row['password'], $password)) {
            return null;
        }
        unset($row['password']); // do not send back to the client
        return $row;
    }

    public function getById(int $id): ?array
    {
        $st = $this->db->prepare("SELECT id, username, role, created_at FROM users WHERE id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** All users in a role (e.g., 'operator'). */
    public function getAllByRole(string $role): array
    {
        $st = $this->db->prepare(
            "SELECT id, username, role, created_at FROM users WHERE role = :role ORDER BY id"
        );
        $st->execute([':role' => $role]);
        return $st->fetchAll();
    }

    public function usernameExists(string $username, int $excludeId = 0): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM users WHERE username = :u AND id <> :id LIMIT 1");
        $st->execute([':u' => $username, ':id' => $excludeId]);
        return (bool)$st->fetchColumn();
    }

    /** CREATE - returns the new user id. */
    public function create(string $username, string $password, string $role): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Invalid role.');
        }
        $st = $this->db->prepare(
            "INSERT INTO users (username, password, role) VALUES (:u, :p, :r)"
        );
        $st->execute([':u' => $username, ':p' => $password, ':r' => $role]); // plain text
        return (int)$this->db->lastInsertId();
    }

    /** UPDATE - operators only. If password is null/blank, it will not be changed. */
    public function updateOperator(int $id, string $username, ?string $password): bool
    {
        $sql    = "UPDATE users SET username = :u";
        $params = [':u' => $username, ':id' => $id];
        if ($password !== null && $password !== '') {
            $sql .= ", password = :p";
            $params[':p'] = $password; // plain text
        }
        $sql .= " WHERE id = :id AND role = 'operator'";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return true;
    }

    /** UPDATE password for current logged-in admin/operator. */
    public function updatePassword(int $id, string $newPassword): bool
    {
        $st = $this->db->prepare("UPDATE users SET password = :p WHERE id = :id");
        $st->execute([':p' => $newPassword, ':id' => $id]);
        return $st->rowCount() > 0;
    }

    /** DELETE - operators only. */
    public function deleteOperator(int $id): bool
    {
        $st = $this->db->prepare("DELETE FROM users WHERE id = :id AND role = 'operator'");
        $st->execute([':id' => $id]);
        return $st->rowCount() > 0;
    }
}