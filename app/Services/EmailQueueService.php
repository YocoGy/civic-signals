<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class EmailQueueService
{
    public const LEASE_SECONDS = 300;
    public const MAX_ATTEMPTS = 5;
    public const RETRY_BASE_SECONDS = 60;
    public const RETRY_MAX_SECONDS = 3600;

    /**
     * Immediately attempts to send one pending queue item.
     *
     * Returns:
     * - true  = email was sent successfully
     * - false = email could not be sent and remains pending/failed
     */
    public static function processOne(
        PDO $pdo,
        int $queueId,
        SmtpMailer $mailer
    ): bool {
        /*
         * Load the queue item.
         */
        $stmt = $pdo->prepare(
            "SELECT
                id,
                signal_id,
                purpose,
                recipient_email,
                template_code,
                payload,
                state,
                attempt_count
             FROM email_queue
             WHERE id = :id
               AND state = 'pending'
               AND available_at <= NOW()
             LIMIT 1"
        );

        $stmt->execute([
            'id' => $queueId,
        ]);

        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return false;
        }

        $workerId = 'web:' . getmypid();

        $attempt =
            (int)$job['attempt_count'] + 1;

        /*
         * Create a unique lease token.
         */
        $leaseToken =
            $workerId . ':' .
            bin2hex(random_bytes(8));

        /*
         * Lease the queue item.
         */
        $lease = $pdo->prepare(
            "UPDATE email_queue
             SET
                state = 'leased',
                lease_owner = :owner,
                lease_until = DATE_ADD(
                    NOW(),
                    INTERVAL " . self::LEASE_SECONDS . " SECOND
                ),
                attempt_count = :attempt
             WHERE id = :id
               AND state = 'pending'
               AND available_at <= NOW()"
        );

        $lease->execute([
            'owner'   => $leaseToken,
            'attempt' => $attempt,
            'id'      => $queueId,
        ]);

        if ($lease->rowCount() !== 1) {
            return false;
        }

        /*
         * Keep track of temporary email attachments.
         *
         * They must be deleted after the attempt, whether
         * the email succeeds or fails.
         */
        $temporaryAttachments = [];

        try {
            $payload = json_decode(
                (string)$job['payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            /*
             * Build email according to its purpose.
             */
            switch ((string)$job['purpose']) {

                case 'validation':

                    $subject =
                        'Потвърждение на граждански сигнал';

                    $body =
                        self::buildValidationEmail(
                            $job,
                            $payload
                        );

                    $attachments = [];

                    break;

                case 'municipality_dispatch':

                    $subject =
                        'Нов граждански сигнал чрез автоматизирана система';

                    $body =
                        self::buildMunicipalityDispatchEmail(
                            $pdo,
                            $job,
                            $payload
                        );

                    $attachments =
                        self::buildMunicipalityAttachments(
                            $pdo,
                            (int)$job['signal_id']
                        );

                    /*
                     * Remember temporary optimizer files so they
                     * can be removed after sending.
                     */
                    foreach ($attachments as $attachment) {
                        if (
                            isset($attachment['temporary']) &&
                            $attachment['temporary'] === true
                        ) {
                            $temporaryAttachments[] =
                                (string)$attachment['path'];
                        }
                    }

                    break;

                default:

                    throw new RuntimeException(
                        'Unsupported email queue purpose: ' .
                        $job['purpose']
                    );
            }

            /*
             * Remove internal metadata before passing attachments
             * to SmtpMailer.
             */
            if ($attachments !== []) {
                $attachments = array_map(
                    static function (array $attachment): array {
                        unset($attachment['temporary']);

                        return $attachment;
                    },
                    $attachments
                );
            }

            /*
             * Send email.
             */
            $result = $mailer->send(
                (string)$job['recipient_email'],
                $subject,
                $body,
                $attachments
            );

            $messageId =
                $result['message_id'] ?? null;

            /*
             * Mark queue item as sent only if this process
             * still owns the lease.
             */
            $complete = $pdo->prepare(
                "UPDATE email_queue
                 SET
                    state = 'sent',
                    sent_at = NOW(),
                    provider_message_id = :message_id,
                    lease_owner = NULL,
                    lease_until = NULL,
                    last_error_code = NULL,
                    last_error_summary = NULL
                 WHERE id = :id
                   AND state = 'leased'
                   AND lease_owner = :owner"
            );

            $complete->execute([
                'message_id' => $messageId,
                'id'         => $queueId,
                'owner'      => $leaseToken,
            ]);

            if ($complete->rowCount() !== 1) {
                throw new RuntimeException(
                    "Email was sent, but queue #{$queueId} " .
                    "could not be marked as sent."
                );
            }

            /*
             * Finalize business state after a successful
             * municipality dispatch.
             */
            if (
                (string)$job['purpose'] ===
                'municipality_dispatch'
            ) {
                self::finalizeMunicipalityDispatch(
                    $pdo,
                    (int)$job['signal_id'],
                    $queueId,
                    (string)$job['recipient_email']
                );
            }

            return true;

        } catch (\Throwable $e) {

            self::handleFailure(
                $pdo,
                $queueId,
                $attempt,
                $leaseToken,
                $e
            );

            return false;

        } finally {

            /*
             * Always remove temporary optimized email copies.
             *
             * The original signal photo is never touched.
             */
            foreach (
                $temporaryAttachments
                as $temporaryPath
            ) {
                if (
                    $temporaryPath !== '' &&
                    is_file($temporaryPath)
                ) {
                    @unlink($temporaryPath);
                }
            }
        }
    }
	
	    /**
     * Processes pending queue items for a specific purpose.
     *
     * The actual sending is delegated to processOne(), so all
     * existing lease, retry and failure handling remains centralized.
     *
     * Returns the number of successfully processed emails.
     */
    public static function processPending(
        PDO $pdo,
        SmtpMailer $mailer,
        string $purpose,
        int $limit = 10
    ): int {
        $limit = max(1, min($limit, 100));

        $stmt = $pdo->prepare(
            "SELECT id
             FROM email_queue
             WHERE state = 'pending'
               AND available_at <= NOW()
               AND purpose = :purpose
             ORDER BY id ASC
             LIMIT {$limit}"
        );

        $stmt->execute([
            'purpose' => $purpose,
        ]);

        $queueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $processed = 0;

        foreach ($queueIds as $queueId) {
            try {
                if (
                    self::processOne(
                        $pdo,
                        (int)$queueId,
                        $mailer
                    )
                ) {
                    $processed++;
                }
            } catch (\Throwable $e) {
                error_log(
                    'Pending email processing failed for queue #'
                    . (int)$queueId
                    . ': '
                    . $e->getMessage()
                );
            }
        }

        return $processed;
    }

    /**
     * Build municipality email attachments.
     *
     * The original photo remains in secure application storage.
     * A small temporary JPEG copy is created specifically
     * for email delivery.
     *
     * @return array<int, array{
     *     path:string,
     *     filename:string,
     *     mime:string,
     *     temporary:bool
     * }>
     */
    private static function buildMunicipalityAttachments(
        PDO $pdo,
        int $signalId
    ): array {
        $stmt = $pdo->prepare(
            'SELECT
                storage_key,
                original_name,
                mime_type,
                file_size
             FROM signal_photos
             WHERE signal_id = :signal_id
             ORDER BY id ASC
             LIMIT 1'
        );

        $stmt->execute([
            'signal_id' => $signalId,
        ]);

        $photo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$photo) {
            throw new RuntimeException(
                'The signal photo could not be found.'
            );
        }

        $storageKey =
            ltrim(
                (string)$photo['storage_key'],
                '/\\'
            );

        /*
         * Prevent path traversal.
         */
        if (
            $storageKey === '' ||
            str_contains($storageKey, '..') ||
            str_contains($storageKey, '\\')
        ) {
            throw new RuntimeException(
                'The signal photo storage path is invalid.'
            );
        }

        $path =
            APP_ROOT .
            '/storage/uploads/' .
            $storageKey;

        if (
            !is_file($path) ||
            !is_readable($path)
        ) {
            throw new RuntimeException(
                'The signal photo file is missing or unreadable.'
            );
        }

        $size = filesize($path);

        if (
            $size === false ||
            $size < 1
        ) {
            throw new RuntimeException(
                'The signal photo file is empty.'
            );
        }

        /*
         * Optimize the original photo for email.
         *
         * The original file is never modified.
         */
        $optimized =
            EmailPhotoOptimizer::optimize(
                $path,
                (string)$photo['original_name']
            );

        return [
            [
                'path' =>
                    $optimized['path'],

                'filename' =>
                    $optimized['filename'],

                'mime' =>
                    $optimized['mime'],

                'temporary' =>
                    true,
            ],
        ];
    }

    /**
     * Finalize a successfully sent municipality dispatch.
     *
     * Moves the signal from READY_FOR_DISPATCH to SENT and
     * records signal.dispatched.
     */
    private static function finalizeMunicipalityDispatch(
        PDO $pdo,
        int $signalId,
        int $queueId,
        string $recipientEmail
    ): void {
        /*
         * Find SENT status.
         */
        $statusStmt = $pdo->prepare(
            "SELECT id
             FROM signal_statuses
             WHERE code = 'SENT'
               AND is_active = 1
             LIMIT 1"
        );

        $statusStmt->execute();

        $sentStatusId =
            (int)$statusStmt->fetchColumn();

        if (!$sentStatusId) {
            throw new RuntimeException(
                'SENT status is not configured.'
            );
        }

        /*
         * Move the signal from READY_FOR_DISPATCH to SENT.
         */
        $update = $pdo->prepare(
            "UPDATE signals
             SET
                signal_status_id = :sent_status,
                dispatched_at = NOW(),
                updated_at = NOW()
             WHERE id = :signal_id
               AND signal_status_id = (
                   SELECT id
                   FROM signal_statuses
                   WHERE code = 'READY_FOR_DISPATCH'
                     AND is_active = 1
                   LIMIT 1
               )"
        );

        $update->execute([
            'sent_status' =>
                $sentStatusId,

            'signal_id' =>
                $signalId,
        ]);

        /*
         * If the signal was already SENT, there is nothing
         * more to do.
         */
        if ($update->rowCount() === 0) {
            return;
        }

        /*
         * Record successful dispatch event.
         */
        $metadata = json_encode(
            [
                'queue_id' =>
                    $queueId,

                'recipient_email' =>
                    $recipientEmail,
            ],
            JSON_THROW_ON_ERROR
        );

        $event = $pdo->prepare(
            'INSERT INTO signal_events (
                signal_id,
                actor_user_id,
                event_code,
                previous_status_id,
                new_status_id,
                metadata
             )
             SELECT
                :signal_id,
                NULL,
                \'signal.dispatched\',
                previous_status.id,
                :new_status,
                :metadata
             FROM signal_statuses previous_status
             WHERE previous_status.code = \'READY_FOR_DISPATCH\'
               AND previous_status.is_active = 1
             LIMIT 1'
        );

        $event->execute([
            'signal_id' =>
                $signalId,

            'new_status' =>
                $sentStatusId,

            'metadata' =>
                $metadata,
        ]);
    }

    /**
     * Handle a failed email attempt.
     */
    private static function handleFailure(
        PDO $pdo,
        int $queueId,
        int $attempt,
        string $leaseToken,
        \Throwable $e
    ): void {
        $errorCode = substr(
            get_class($e),
            strrpos(
                get_class($e),
                '\\'
            ) + 1
        );

        $errorSummary = substr(
            $e->getMessage(),
            0,
            1000
        );

        /*
         * Retry with exponential backoff.
         *
         * 1st failure: 60 sec
         * 2nd failure: 120 sec
         * 3rd failure: 240 sec
         * 4th failure: 480 sec
         * 5th failure: failed
         */
        if (
            $attempt >=
            self::MAX_ATTEMPTS
        ) {
            $retryState = 'failed';
            $delay = 0;
        } else {
            $retryState = 'pending';

            $delay = min(
                self::RETRY_BASE_SECONDS *
                (2 ** ($attempt - 1)),
                self::RETRY_MAX_SECONDS
            );
        }

        $update = $pdo->prepare(
            "UPDATE email_queue
             SET
                state = :state,
                available_at = CASE
                    WHEN :delay > 0
                    THEN DATE_ADD(
                        NOW(),
                        INTERVAL :delay SECOND
                    )
                    ELSE available_at
                END,
                lease_owner = NULL,
                lease_until = NULL,
                last_error_code = :error_code,
                last_error_summary = :error_summary
             WHERE id = :id
               AND state = 'leased'
               AND lease_owner = :owner"
        );

        $update->execute([
            'state' =>
                $retryState,

            'delay' =>
                $delay,

            'error_code' =>
                $errorCode,

            'error_summary' =>
                $errorSummary,

            'id' =>
                $queueId,

            'owner' =>
                $leaseToken,
        ]);
    }

    /**
     * Build the validation email.
     */
    public static function buildValidationEmail(
        array $job,
        array $payload
    ): string {
        $reference = (string)(
            $payload['signal_reference'] ?? ''
        );

        $token = (string)(
            $payload['validation_token'] ?? ''
        );

        if (
            $reference === '' ||
            $token === ''
        ) {
            throw new RuntimeException(
                'Validation email payload is incomplete.'
            );
        }

        $baseUrl = rtrim(
            (string)app_env('APP_URL'),
            '/'
        );

        $validationUrl =
            $baseUrl .
            '/validate-email.php?reference=' .
            rawurlencode($reference) .
            '&token=' .
            rawurlencode($token);

        return
            "Здравейте,\n\n" .

            "Получихме Вашия граждански сигнал.\n\n" .

            "За да потвърдите имейл адреса и да активирате сигнала, " .
            "отворете следния линк:\n\n" .

            $validationUrl .

            "\n\n" .

            "Референция на сигнала: " .
            $reference .

            "\n\n" .

            "Линкът е валиден 24 часа.\n\n" .

            "Ако не сте изпращали този сигнал, можете да игнорирате " .
            "това съобщение.\n\n" .

            "Граждански сигнал";
    }

    /**
     * Build the municipality dispatch email.
     */
    public static function buildMunicipalityDispatchEmail(
        PDO $pdo,
        array $job,
        array $payload
    ): string {
        $reference = (string)(
            $payload['signal_reference'] ?? ''
        );

        $municipalityName = (string)(
            $payload['municipality_name'] ?? ''
        );

        $ekatteCode = (string)(
            $payload['ekatte_code'] ?? ''
        );

        $latitude = (string)(
            $payload['latitude'] ?? ''
        );

        $longitude = (string)(
            $payload['longitude'] ?? ''
        );

        $contactAllowed =
            !empty(
                $payload['reporter_contact_allowed']
            );

        if (
            $reference === '' ||
            $municipalityName === '' ||
            $latitude === '' ||
            $longitude === ''
        ) {
            throw new RuntimeException(
                'Municipality dispatch email payload is incomplete.'
            );
        }

        /*
         * Validate coordinates before putting them into a
         * clickable map URL.
         */
        if (
            !is_numeric($latitude) ||
            !is_numeric($longitude)
        ) {
            throw new RuntimeException(
                'Signal GPS coordinates are invalid.'
            );
        }

        $latitudeValue =
            (float)$latitude;

        $longitudeValue =
            (float)$longitude;

        if (
            !is_finite($latitudeValue) ||
            !is_finite($longitudeValue) ||
            $latitudeValue < -90 ||
            $latitudeValue > 90 ||
            $longitudeValue < -180 ||
            $longitudeValue > 180
        ) {
            throw new RuntimeException(
                'Signal GPS coordinates are outside the allowed range.'
            );
        }

        /*
         * OpenStreetMap map URL.
         */
		$mapUrl =
			'https://maps.google.com/?q=' .
			rawurlencode(
				number_format(
					$latitudeValue,
					7,
					'.',
					''
				)
			) .
			',' .
			rawurlencode(
				number_format(
					$longitudeValue,
					7,
					'.',
					''
				)
			) .
            '#map=18/' .
            rawurlencode(
                number_format(
                    $latitudeValue,
                    7,
                    '.',
                    ''
                )
            ) .
            '/' .
            rawurlencode(
                number_format(
                    $longitudeValue,
                    7,
                    '.',
                    ''
                )
            );

        $body =
            "Здравейте,\n\n" .

            "Това съобщение е изпратено автоматично от " .
            "системата за подаване на граждански сигнали чрез снимка.\n\n" .

            "В системата е регистриран нов граждански сигнал, " .
            "който автоматично е насочен към " .
            "Община {$municipalityName}.\n\n" .

            "РЕФЕРЕНЦИЯ НА СИГНАЛА\n" .
            $reference .
            "\n\n" .

            "МЕСТОПОЛОЖЕНИЕ\n" .
            "Община: " .
            $municipalityName .
            "\n" .

            "ЕКАТТЕ код: " .
            $ekatteCode .
            "\n" .

            "GPS координати:\n" .
            "Latitude: " .
            number_format(
                $latitudeValue,
                7,
                '.',
                ''
            ) .
            "\n" .

            "Longitude: " .
            number_format(
                $longitudeValue,
                7,
                '.',
                ''
            ) .
            "\n\n" .

            "Карта:\n" .
            $mapUrl .
            "\n\n" .

            "ИНФОРМАЦИЯ ЗА СИГНАЛА\n" .
            "Сигналът е потвърден от подателя чрез посочения " .
            "e-mail адрес и след потвърждението е автоматично " .
            "пренасочен към Вас за последващи действия.\n\n";

        if ($contactAllowed) {
            $reporterEmail = trim(
                (string)(
                    $payload['reporter_email'] ?? ''
                )
            );

            if ($reporterEmail !== '') {
                $body .=
                    "КОНТАКТ С ПОДАТЕЛЯ\n" .
                    "Подателят е разрешил да бъде потърсен " .
                    "по e-mail при необходимост от допълнителна " .
                    "информация относно сигнала.\n" .
                    "E-mail: " .
                    $reporterEmail .
                    "\n\n";
            }
        } else {
            $body .=
                "КОНТАКТ С ПОДАТЕЛЯ\n" .
                "Подателят не е разрешил предоставяне на " .
                "e-mail адреса му за допълнителен контакт.\n\n";
        }

        $body .=
            "ПРИКАЧЕН ФАЙЛ\n" .
            "Към това съобщение е приложено оптимизирано " .
            "копие на снимката, изпратена със сигнала.\n\n" .

            "Оригиналната снимка се съхранява отделно в системата " .
            "и не се променя при подготовката на email съобщението.\n\n" .

            "Това съобщение е генерирано автоматично. " .
            "Моля, не отговаряйте на него, освен ако системата " .
            "не е конфигурирана да приема отговори на този адрес.\n\n" .

            "Граждански сигнали";

        return $body;
    }
}