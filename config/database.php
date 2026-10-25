<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $servername = 'localhost';
            $username = 'root';
            $password = '';
            $dbname = 'restauranttable';

            $dsn = "mysql:host={$servername};dbname={$dbname};charset=utf8mb4";

            try {
                self::$connection = new PDO(
                    $dsn,
                    $username,
                    $password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log((string) $e);

                throw new RuntimeException(
                    'Database connection failed.',
                    0,
                    $e
                );
            }
        }

        return self::$connection;
    }
}