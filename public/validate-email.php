<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Database\Connection;
use App\Services\MunicipalityDispatchService;
use App\Services\EmailQueueService;

$reference = strtoupper(
    trim((string)($_GET['reference'] ?? ''))
);

$token = trim(
    (string)($_GET['token'] ?? '')
);

function validationPage(
    string $title,
    string $message,
    int $status = 200
): never {
    http_response_code($status);

    echo '<!doctype html>';
    echo '<html lang="bg">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' .
        htmlspecialchars(
            $title,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</title>';

    echo '<style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            margin: 0;
            padding: 40px 20px;
        }

        .box {
            max-width: 650px;
            margin: 40px auto;
            background: #fff;
            padding: 35px;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
        }

        h1 {
            margin-top: 0;
        }

        p {
            line-height: 1.6;
        }
    </style>';

    echo '</head>';
    echo '<body>';
    echo '<div class="box">';

    echo '<h1>' .
        htmlspecialchars(
            $title,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</h1>';

    echo '<p>' .
        nl2br(
            htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            )
        ) .
        '</p>';

    echo '</div>';
    echo '</body>';
    echo '</html>';

    exit;
}

try {

    /*
     * Validate request parameters.
     */
    if (
        $reference === '' ||
        $token === ''
    ) {
        validationPage(
            'Невалиден линк',
            'Липсват необходимите параметри за потвърждение на сигнала.',
            400
        );
    }

    if (
        !preg_match(
            '/^[A-F0-9]{26}$/',
            $reference
        ) ||
        !preg_match(
            '/^[a-f0-9]{64}$/',
            $token
        )
    ) {
        validationPage(
            'Невалиден линк',
            'Линкът за потвърждение е невалиден.',
            400
        );
    }

    /*
     * Application security key.
     */
    $key = app_env('APP_KEY');

    if (
        $key === null ||
        strlen($key) < 32
    ) {
        throw new RuntimeException(
            'Application security key is not configured.'
        );
    }

    $pdo = Connection::database();

    /*
     * Everything up to the creation of the municipality
     * dispatch queue is performed atomically.
     */
    $pdo->beginTransaction();

    try {

        /*
         * Find the signal and its latest validation record.
         *
         * FOR UPDATE prevents two simultaneous requests from
         * consuming the same validation token.
         */
        $stmt = $pdo->prepare(
            'SELECT
                s.id AS signal_id,
                s.public_reference,
                s.signal_status_id,
                sev.id AS validation_id,
                sev.token_hash,
                sev.token_version,
                sev.issued_at,
                sev.expires_at,
                sev.consumed_at
             FROM signals s
             INNER JOIN signal_email_validations sev
                ON sev.signal_id = s.id
             WHERE s.public_reference = :reference
             ORDER BY sev.id DESC
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([
            'reference' => $reference,
        ]);

        $validation = $stmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$validation) {

            $pdo->rollBack();

            validationPage(
                'Невалиден линк',
                'Сигналът или линкът за потвърждение не съществува.',
                404
            );
        }

        /*
         * Calculate the same HMAC used when the
         * validation token was created.
         */
        $tokenHash = hash_hmac(
            'sha256',
            $token,
            $key
        );

        if (
            !hash_equals(
                (string)$validation['token_hash'],
                $tokenHash
            )
        ) {

            $pdo->rollBack();

            validationPage(
                'Невалиден линк',
                'Линкът за потвърждение е невалиден.',
                400
            );
        }

        /*
         * A validation token can only be consumed once.
         */
        if (
            $validation['consumed_at'] !== null
        ) {

            $pdo->rollBack();

            validationPage(
                'Сигналът вече е потвърден',
                'Този линк за потвърждение вече е използван.'
            );
        }

        /*
         * Check expiration.
         */
        $expiresAt = new DateTimeImmutable(
            (string)$validation['expires_at']
        );

        if (
            $expiresAt <= new DateTimeImmutable()
        ) {

            $pdo->rollBack();

            validationPage(
                'Линкът е изтекъл',
                'Линкът за потвърждение е изтекъл. Необходимо е да бъде изпратен нов линк.',
                410
            );
        }

        /*
         * Find EMAIL_VALIDATED status.
         */
        $statusStmt = $pdo->prepare(
            "SELECT id
             FROM signal_statuses
             WHERE code = 'EMAIL_VALIDATED'
               AND is_active = 1
             LIMIT 1"
        );

        $statusStmt->execute();

        $validatedStatusId =
            (int)$statusStmt->fetchColumn();

        if (!$validatedStatusId) {
            throw new RuntimeException(
                'EMAIL_VALIDATED status is not configured.'
            );
        }

        $previousStatusId =
            (int)$validation['signal_status_id'];

        /*
         * Consume validation token.
         */
        $consume = $pdo->prepare(
            'UPDATE signal_email_validations
             SET consumed_at = NOW()
             WHERE id = :id
               AND consumed_at IS NULL'
        );

        $consume->execute([
            'id' =>
                (int)$validation['validation_id'],
        ]);

        if (
            $consume->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Validation token could not be consumed.'
            );
        }

        /*
         * Move signal to EMAIL_VALIDATED.
         */
        $updateSignal = $pdo->prepare(
            'UPDATE signals
             SET
                 signal_status_id = :new_status,
                 email_validated_at = NOW(),
                 updated_at = NOW()
             WHERE id = :signal_id
               AND signal_status_id = :previous_status'
        );

        $updateSignal->execute([
            'new_status' =>
                $validatedStatusId,

            'signal_id' =>
                (int)$validation['signal_id'],

            'previous_status' =>
                $previousStatusId,
        ]);

        if (
            $updateSignal->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Signal status could not be updated.'
            );
        }

        /*
         * Record EMAIL_VALIDATED event.
         */
        $metadata = json_encode(
            [
                'validation_id' =>
                    (int)$validation['validation_id'],

                'token_version' =>
                    (int)$validation['token_version'],
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
             ) VALUES (
                :signal_id,
                NULL,
                :event_code,
                :previous_status,
                :new_status,
                :metadata
             )'
        );

        $event->execute([
            'signal_id' =>
                (int)$validation['signal_id'],

            'event_code' =>
                'signal.email.validated',

            'previous_status' =>
                $previousStatusId,

            'new_status' =>
                $validatedStatusId,

            'metadata' =>
                $metadata,
        ]);

        /*
         * Prepare municipality dispatch.
         *
         * IMPORTANT:
         *
         * MunicipalityDispatchService ONLY creates the queue
         * item here. It does NOT send the email.
         *
         * The actual email will be sent by email-worker.php.
         */
        $dispatch =
            MunicipalityDispatchService::prepare(
                $pdo,
                (int)$validation['signal_id']
            );

        /*
         * Commit:
         *
         * 1. email validation
         * 2. signal status
         * 3. dispatch preparation
         * 4. municipality email queue
         *
         * Everything becomes durable together.
         */
        $pdo->commit();
		
				/*
		 * Process pending municipality emails immediately.
		 *
		 * The queue has already been committed, so the email
		 * processing is deliberately performed outside the transaction.
		 *
		 * If SMTP fails, the queue item remains pending and can be
		 * processed by a subsequent request.
		 */
		try {
			$mailer = new \App\Services\SmtpMailer();

			EmailQueueService::processPending(
				$pdo,
				$mailer,
				'municipality_dispatch',
				10
			);

		} catch (Throwable $e) {

			/*
			 * The signal has already been validated and committed.
			 * A temporary SMTP failure must not turn this into
			 * a validation failure.
			 */
			error_log(
				'Municipality email processing failed after validation for signal #'
				. (int)$validation['signal_id']
				. ': '
				. $e->getMessage()
			);
		}

    } catch (Throwable $e) {

        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }

	/*
	 * Municipality emails have already been processed immediately
	 * after the transaction was committed.
	 *
	 * If SMTP delivery failed, the queue item remains pending and
	 * can be processed by a subsequent request.
	 */

	validationPage(
		'Сигналът е потвърден',
		"Вашият имейл адрес беше успешно потвърден.\n\n" .
		"Референция на сигнала: " .
		$validation['public_reference'] .
		"\n\n" .
		'Сигналът е насочен към Община ' .
		$dispatch['municipality']['name'] .
		' и е изпратен за автоматично изпълнение.'
	);

} catch (Throwable $e) {

    error_log(
        'Email validation error: ' .
        $e->getMessage()
    );

    validationPage(
        'Възникна грешка',
        'Възникна техническа грешка при потвърждаването на сигнала. Моля, опитайте отново по-късно.',
        500
    );
}