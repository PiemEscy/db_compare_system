<?php

class DatabaseHelper
{
    public static function connectSystem()
    {
        $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $dbPort = $_ENV['DB_PORT'] ?? '3306';
        $dbDatabase = $_ENV['DB_DATABASE'] ?? '';
        $dbUsername = $_ENV['DB_USERNAME'] ?? 'root';
        $dbPassword = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbDatabase};charset=utf8mb4";

        try {
            return new PDO($dsn, $dbUsername, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
}
