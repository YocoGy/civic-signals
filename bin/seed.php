<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use App\Database\Connection;
$pdo = Connection::database();
foreach (glob(APP_ROOT . '/database/seed/*.sql') ?: [] as $file) {
    $pdo->exec((string) file_get_contents($file));
    echo 'SEEDED ' . basename($file) . PHP_EOL;
}
