<?php
declare(strict_types=1);
namespace App\Security;

use RuntimeException;

final class CSRF
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function validate(string $token): void
    {
        if (empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
            throw new RuntimeException('CSRF validation failed.');
        }
    }
}
