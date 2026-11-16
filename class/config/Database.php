<?php

class Database
{
    private string $host     = 'localhost';
    private string $dbName   = 'parking_system';
    private string $username = 'root';   
    private string $password = '';   
    private string $charset  = 'utf8mb4';

    private ?PDO $conn = null;

    public function getConnection(): PDO
    {
        if ($this->conn === null) {
            $dsn = "mysql:host={$this->host};dbname={$this->dbName};charset={$this->charset}";
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $this->conn;
    }
}