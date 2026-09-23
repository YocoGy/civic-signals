<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\Security\CSRF;
use App\Services\PhotoInspector;
use App\Services\PublicSignalService;
use App\Services\PublicSubmissionRateLimiter;
use App\Validation\EmailValidator;

try {
    /*
     * Only POST requests are accepted.
     */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException(
            'Методът на заявката не е разрешен.'
        );
    }

    /*
     * CSRF protection.
     */
    CSRF::validate(
        (string)($_POST['_csrf'] ?? '')
    );

    /*
     * Honeypot field.
     *
     * Normal users never see/use this field.
     * A filled value indicates an automated request.
     */
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        throw new RuntimeException(
            'Заявката не може да бъде приета.'
        );
    }

    /*
     * Privacy / information acknowledgement.
     *
     * This acknowledgement is mandatory.
     *
     * The checkbox is deliberately checked server-side.
     * JavaScript is only a user-interface convenience and
     * is NOT considered a security control.
     */
    $privacyAcknowledged =
        isset($_POST['privacy_acknowledged'])
        && $_POST['privacy_acknowledged'] === '1';

    if (!$privacyAcknowledged) {
        throw new RuntimeException(
            'За да подадете сигнал, трябва да потвърдите, че сте запознати с начина на работа на системата и информацията за обработването на лични данни.'
        );
    }

    /*
     * Optional permission for contact by the competent
     * municipality.
     *
     * This permission is NOT required for submitting a signal.
     *
     * Any value other than exactly "1" means that permission
     * was not given.
     */
    $reporterContactAllowed =
        isset($_POST['reporter_contact_allowed'])
        && $_POST['reporter_contact_allowed'] === '1';

    /*
     * Normalize and validate email.
     */
    $email = EmailValidator::normalize(
        (string)($_POST['email'] ?? '')
    );

    /*
     * Identify the submitting client.
     */
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $pdo = Connection::database();

    /*
     * Public submission rate limiting.
     */
    PublicSubmissionRateLimiter::assertAllowed(
        $pdo,
        $email,
        $ip
    );

    /*
     * Validate uploaded file.
     */
    if (
        !isset($_FILES['photo'])
        || is_array($_FILES['photo']['name'])
        || $_FILES['photo']['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file($_FILES['photo']['tmp_name'])
    ) {
        throw new RuntimeException(
            'Изберете една валидна снимка.'
        );
    }

    /*
     * Move uploaded file to the temporary secure location.
     */
    $tmpDir =
        APP_ROOT .
        '/storage/uploads/tmp';

    if (!is_dir($tmpDir)) {
        if (
            !mkdir($tmpDir, 0750, true)
            && !is_dir($tmpDir)
        ) {
            throw new RuntimeException(
                'Временната директория за снимката не може да бъде създадена.'
            );
        }
    }

    $temporary =
        $tmpDir .
        '/' .
        bin2hex(random_bytes(24)) .
        '.upload';

    if (
        !move_uploaded_file(
            $_FILES['photo']['tmp_name'],
            $temporary
        )
    ) {
        throw new RuntimeException(
            'Снимката не може да бъде приета.'
        );
    }

    /*
     * Inspect the actual image and its EXIF metadata.
     */
    $photo = PhotoInspector::inspect(
        $temporary,
        (string)$_FILES['photo']['name']
    );

    $photo['original_name'] =
        (string)$_FILES['photo']['name'];

    /*
     * Create the signal.
     *
     * PublicSignalService performs the final server-side
     * mandatory privacy acknowledgement check as well.
     *
     * The optional reporter-contact permission is passed
     * separately and does not affect signal submission.
     */
    $reference = PublicSignalService::create(
        $pdo,
        $email,
        $photo,
        $temporary,
        $privacyAcknowledged,
        $reporterContactAllowed
    );

    /*
     * Record successful submission only after the signal
     * has been successfully created.
     */
    PublicSubmissionRateLimiter::record(
        $pdo,
        $email,
        $ip,
        true
    );

    /*
     * Rotate the CSRF token after a successful submission.
     */
    $_SESSION['_csrf'] =
        bin2hex(random_bytes(32));

    /*
     * The validation email is attempted immediately
     * by PublicSignalService.
     *
     * If SMTP is temporarily unavailable, the queue/worker
     * mechanism will handle the retry.
     */
    json_response([
        'ok' => true,
        'reference' => $reference,
        'message' =>
            'Сигналът беше приет успешно. ' .
            'На посочения e-mail адрес ще получите линк за потвърждение.'
    ]);

} catch (Throwable $e) {

    /*
     * Failed submission attempt.
     */
    if (
        isset($pdo, $email, $ip)
    ) {
        PublicSubmissionRateLimiter::record(
            $pdo,
            $email,
            $ip,
            false
        );
    }

    /*
     * Remove temporary upload if it still exists.
     *
     * Once PublicSignalService successfully moves the file
     * to permanent storage, this path no longer exists.
     */
    if (
        isset($temporary)
        && is_file($temporary)
    ) {
        @unlink($temporary);
    }

    json_response([
        'ok' => false,
        'message' => $e->getMessage()
    ], 422);
}