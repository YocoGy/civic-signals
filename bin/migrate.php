<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use App\Database\Connection;

$database = app_env('DB_DATABASE', 'civic_signals');
if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) throw new RuntimeException('Invalid DB_DATABASE.');
$server = Connection::server();
if (filter_var(app_env('DB_CREATE_IF_MISSING', 'true'), FILTER_VALIDATE_BOOL)) {
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$pdo = Connection::database();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    $check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
    $check->execute(['migration' => $name]);
    if ($check->fetchColumn()) { echo "SKIP {$name}\n"; continue; }
    try {
        $pdo->exec((string) file_get_contents($file));
        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)')->execute(['migration' => $name]);
        echo "APPLIED {$name}\n";
    } catch (Throwable $e) { throw $e; }
}
