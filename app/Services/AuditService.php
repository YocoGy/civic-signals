<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class AuditService
{
    public static function record(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, string $status = 'success', array $metadata = []): void
    {
        $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, status, ip_address, request_method, route, session_id, metadata) VALUES (:user_id,:action,:entity_type,:entity_id,:status,:ip,:method,:route,:session,:metadata)');
        $stmt->execute([
            'user_id' => $userId, 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'status' => $status, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'route' => $_SERVER['REQUEST_URI'] ?? null, 'session' => session_id() ?: null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
