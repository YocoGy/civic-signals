<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

final class PublicSubmissionRateLimiter
{
    public static function assertAllowed(PDO $pdo, string $email, string $ip): void
    {
        $hash = hash('sha256', $email);
        $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM public_submission_attempts WHERE email_hash=:hash AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS email_count, (SELECT COUNT(*) FROM public_submission_attempts WHERE ip_address=:ip AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ip_count');
        $stmt->execute(['hash'=>$hash,'ip'=>$ip]); $row = $stmt->fetch();
        if ((int)$row['email_count'] >= 3 || (int)$row['ip_count'] >= 10) throw new \RuntimeException('Too many submission attempts. Please try again later.');
    }
    public static function record(PDO $pdo, string $email, string $ip, bool $accepted): void
    {
        $pdo->prepare('INSERT INTO public_submission_attempts (email_hash,ip_address,accepted) VALUES (:hash,:ip,:accepted)')->execute(['hash'=>hash('sha256',$email),'ip'=>$ip,'accepted'=>$accepted ? 1 : 0]);
    }
}
