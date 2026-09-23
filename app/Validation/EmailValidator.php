<?php
declare(strict_types=1);
namespace App\Validation;

final class EmailValidator
{
    public static function normalize(string $email): string
    {
        $email = trim($email);
        if ($email === '' || str_contains($email, "\r") || str_contains($email, "\n") || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        return mb_strtolower($email);
    }
}
