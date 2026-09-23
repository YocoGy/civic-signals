<?php
declare(strict_types=1);
namespace App\Database;

use PDO;
use RuntimeException;

final class Connection
{
    public static function server(): PDO
    {
        $host = app_env('DB_HOST', '127.0.0.1');
        $port = app_env('DB_PORT', '3306');
        return self::pdo("mysql:host={$host};port={$port};charset=utf8mb4");
    }

    public static function database(): PDO
    {
        $database = app_env('DB_DATABASE', 'civic_signals');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('DB_DATABASE may contain only letters, numbers and underscores.');
        }
        $host = app_env('DB_HOST', '127.0.0.1');
        $port = app_env('DB_PORT', '3306');
        return self::pdo("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4");
    }

    private static function pdo(string $dsn): PDO
    {
        return new PDO($dsn, app_env('DB_USERNAME', 'root'), app_env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
