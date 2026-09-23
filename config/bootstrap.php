<?php
declare(strict_types=1);

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Europe/Sofia');

/** Loads simple KEY=VALUE local configuration without an external package. */
function app_env(string $name, ?string $default = null): ?string
{
    static $values = null;
    if ($values === null) {
        $values = [];
        $path = APP_ROOT . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim(trim($value), "\"'");
            }
        }
    }
    $environment = getenv($name);
    return $environment !== false ? $environment : ($values[$name] ?? $default);
}

require APP_ROOT . '/app/autoload.php';
