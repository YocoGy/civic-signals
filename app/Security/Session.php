<?php
declare(strict_types=1);
namespace App\Security;

final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
        if (empty($_SESSION['_session_started'])) {
            session_regenerate_id(true);
            $_SESSION['_session_started'] = time();
        }
    }
}
