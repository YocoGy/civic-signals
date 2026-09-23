<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class PublicSignalService
{
    /**
     * Current version of the public privacy/information notice.
     *
     * This value is controlled by the application and must not
     * be supplied by the browser.
     */
    private const PRIVACY_POLICY_VERSION = '1.0';

    /**
     * Current version of the optional reporter-contact notice.
     *
     * This value is controlled by the application and must not
     * be supplied by the browser.
     */
    private const CONTACT_POLICY_VERSION = '1.0';

    /**
     * Creates the initial pending signal, queues the email-validation
     * message and immediately attempts to send it.
     *
     * If the immediate email attempt fails, the queue item remains
     * pending and will be retried by email-worker.php.
     */
    public static function create(
        PDO $pdo,
        string $email,
        array $photo,
        string $temporaryPath,
        bool $privacyAcknowledged,
        bool $reporterContactAllowed
    ): string {
        /*
         * Privacy/information acknowledgement is mandatory.
         *
         * This is a server-side check. The JavaScript checkbox
         * in the public form is only a user-interface convenience
         * and must never be considered a security control.
         */
        if (!$privacyAcknowledged) {
            throw new \RuntimeException(
                'За да подадете сигнал, трябва да потвърдите, че сте запознати с начина на работа на системата и информацията за обработването на лични данни.'
            );
        }

        $duplicate = $pdo->prepare(
            'SELECT s.public_reference
             FROM signals s
             JOIN signal_photos p ON p.signal_id = s.id
             WHERE s.reporter_email = :email
               AND p.sha256_hash = :hash
               AND s.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1'
        );

        $duplicate->execute([
            'email' => $email,
            'hash'  => $photo['sha256_hash']
        ]);

        if ($reference = $duplicate->fetchColumn()) {
            throw new \RuntimeException(
                "Тази снимка вече е подадена наскоро (референция {$reference})."
            );
        }

        $pending = (int) $pdo
            ->query(
                "SELECT id
                 FROM signal_statuses
                 WHERE code = 'PENDING_EMAIL_VALIDATION'
                   AND is_active = 1"
            )
            ->fetchColumn();

        if (!$pending) {
            throw new \RuntimeException(
                'Initial signal status is not configured.'
            );
        }

        $reference = strtoupper(
            bin2hex(random_bytes(13))
        );

        /*
         * Plain token exists only during this request and is used
         * for the email validation queue.
         */
        $token = bin2hex(
            random_bytes(32)
        );

        $key = app_env('APP_KEY');

        if (
            $key === null
            || strlen($key) < 32
        ) {
            throw new \RuntimeException(
                'Application security key is not configured.'
            );
        }

        /*
         * Only the HMAC hash is stored in
         * signal_email_validations.
         */
        $tokenHash = hash_hmac(
            'sha256',
            $token,
            $key
        );

        $storageDir = APP_ROOT
            . '/storage/uploads/originals/'
            . substr($reference, 0, 2);

        if (
            !is_dir($storageDir)
            && !mkdir($storageDir, 0750, true)
            && !is_dir($storageDir)
        ) {
            throw new \RuntimeException(
                'Secure storage cannot be created.'
            );
        }

        $storageKey =
            'originals/'
            . substr($reference, 0, 2)
            . '/'
            . bin2hex(random_bytes(24))
            . '.jpg';

        $destination = APP_ROOT
            . '/storage/uploads/'
            . $storageKey;

        $queueId = null;

        $pdo->beginTransaction();

        try {
            /*
             * 1. Create signal
             */
            $stmt = $pdo->prepare(
                'INSERT INTO signals
                (
                    public_reference,
                    reporter_email,

                    privacy_policy_version,
                    privacy_acknowledged_at,

                    reporter_contact_allowed,
                    reporter_contact_allowed_at,
                    reporter_contact_policy_version,

                    signal_status_id,
                    latitude,
                    longitude,
                    gps_source,
                    captured_at_local,
                    submitted_at
                )
                VALUES
                (
                    :reference,
                    :email,

                    :privacy_policy_version,
                    NOW(),

                    :reporter_contact_allowed,
                    :reporter_contact_allowed_at,
                    :reporter_contact_policy_version,

                    :status,
                    :latitude,
                    :longitude,
                    \'exif_original\',
                    :captured,
                    NOW()
                )'
            );

            $stmt->execute([
                'reference' =>
                    $reference,

                'email' =>
                    $email,

                'privacy_policy_version' =>
                    self::PRIVACY_POLICY_VERSION,

                'reporter_contact_allowed' =>
                    $reporterContactAllowed ? 1 : 0,

                'reporter_contact_allowed_at' =>
                    $reporterContactAllowed
                        ? date('Y-m-d H:i:s')
                        : null,

                'reporter_contact_policy_version' =>
                    $reporterContactAllowed
                        ? self::CONTACT_POLICY_VERSION
                        : null,

                'status' =>
                    $pending,

                'latitude' =>
                    $photo['latitude'],

                'longitude' =>
                    $photo['longitude'],

                'captured' =>
                    $photo['captured_at']
            ]);

            $signalId = (int) $pdo->lastInsertId();

            /*
             * 2. Store original photo
             */
            if (!rename(
                $temporaryPath,
                $destination
            )) {
                throw new \RuntimeException(
                    'The original photo could not be stored.'
                );
            }

            $safeName = preg_replace(
                '/[\x00-\x1F\\\\\/\"]+/',
                '_',
                basename(
                    (string) $photo['original_name']
                )
            ) ?: 'photo.jpg';

            $stmt = $pdo->prepare(
                'INSERT INTO signal_photos
                (
                    signal_id,
                    storage_key,
                    original_name,
                    mime_type,
                    file_size,
                    sha256_hash,
                    width,
                    height,
                    exif_datetime_original
                )
                VALUES
                (
                    :signal,
                    :key,
                    :name,
                    :mime,
                    :size,
                    :hash,
                    :width,
                    :height,
                    :exif
                )'
            );

            $stmt->execute([
                'signal' =>
                    $signalId,

                'key' =>
                    $storageKey,

                'name' =>
                    $safeName,

                'mime' =>
                    $photo['mime_type'],

                'size' =>
                    $photo['file_size'],

                'hash' =>
                    $photo['sha256_hash'],

                'width' =>
                    $photo['width'],

                'height' =>
                    $photo['height'],

                'exif' =>
                    $photo['exif_datetime_original']
            ]);

            /*
             * 3. Create email-validation record.
             *
             * Only the HMAC token hash is stored here.
             * The plaintext token is never stored in this table.
             */
            $stmt = $pdo->prepare(
                'INSERT INTO signal_email_validations
                (
                    signal_id,
                    token_hash,
                    issued_at,
                    expires_at
                )
                VALUES
                (
                    :signal,
                    :hash,
                    NOW(),
                    DATE_ADD(NOW(), INTERVAL 24 HOUR)
                )'
            );

            $stmt->execute([
                'signal' =>
                    $signalId,

                'hash' =>
                    $tokenHash
            ]);

            /*
             * 4. Create audit/event record.
             */
            $pdo->prepare(
                'INSERT INTO signal_events
                (
                    signal_id,
                    event_code,
                    new_status_id,
                    metadata
                )
                VALUES
                (
                    :signal,
                    \'signal.created\',
                    :status,
                    :meta
                )'
            )->execute([
                'signal' =>
                    $signalId,

                'status' =>
                    $pending,

                'meta' =>
                    json_encode(
                        [
                            'gps_source' =>
                                'exif_original',

                            'reporter_contact_allowed' =>
                                $reporterContactAllowed
                        ],
                        JSON_THROW_ON_ERROR
                    )
            ]);

            /*
             * 5. Queue email-validation message.
             */
            $recipientHash = hash(
                'sha256',
                strtolower(trim($email))
            );

            $idempotencyKey = hash(
                'sha256',
                'validation:' . $signalId
            );

            $payload = json_encode(
                [
                    'validation_token' =>
                        $token,

                    'signal_reference' =>
                        $reference
                ],
                JSON_THROW_ON_ERROR
            );

            $stmt = $pdo->prepare(
                'INSERT INTO email_queue
                (
                    signal_id,
                    municipality_contact_id,
                    purpose,
                    recipient_email,
                    recipient_hash,
                    template_code,
                    payload,
                    state,
                    idempotency_key,
                    attempt_count,
                    available_at
                )
                VALUES
                (
                    :signal,
                    NULL,
                    \'validation\',
                    :email,
                    :recipient_hash,
                    \'signal_email_validation\',
                    :payload,
                    \'pending\',
                    :idempotency_key,
                    0,
                    NOW()
                )'
            );

            $stmt->execute([
                'signal' =>
                    $signalId,

                'email' =>
                    $email,

                'recipient_hash' =>
                    $recipientHash,

                'payload' =>
                    $payload,

                'idempotency_key' =>
                    $idempotencyKey
            ]);

            $queueId =
                (int) $pdo->lastInsertId();

            /*
             * 6. Commit everything atomically.
             */
            $pdo->commit();

        } catch (\Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (is_file(
                $destination ?? ''
            )) {
                @unlink($destination);
            }

            throw $e;
        }

        /*
         * 7. Immediate first email attempt.
         *
         * This happens AFTER commit.
         *
         * If SMTP fails, EmailQueueService leaves the queue item
         * pending so the daemon can retry it later.
         *
         * We deliberately do not fail the signal submission here:
         * the signal itself was already committed successfully.
         */
        if ($queueId !== null) {
			try {
				$mailer = new SmtpMailer();

				EmailQueueService::processPending(
					$pdo,
					$mailer,
					'validation',
					10
				);

			} catch (\Throwable $e) {

				/*
				 * The queue remains available for a subsequent request.
				 *
				 * Log the problem, but do not invalidate the
				 * successfully saved signal.
				 */
				error_log(
					'Validation email processing failed for '
					. 'signal #'
					. $signalId
					. ': '
					. $e->getMessage()
				);
			}
		}

        return $reference;
    }
}