<?php
declare(strict_types=1);
namespace App\Security;

final class Authorization
{
    public static function hasPermission(string $permission): bool
    {
        return in_array($permission, $_SESSION['permissions'] ?? [], true);
    }
}
