<?php
declare(strict_types=1);
namespace App\Security;

use PDO;

/** Persistence primitive for later public/admin endpoint policies; no workflow is implemented here. */
final class RateLimiter
{
    public static function recordLogin(PDO $pdo, string $identifier, string $ip, bool $success): void
    {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier_hash, ip_address, success) VALUES (:identifier, :ip, :success)');
        $stmt->execute(['identifier' => hash('sha256', mb_strtolower(trim($identifier))), 'ip' => $ip, 'success' => $success ? 1 : 0]);
    }
}
