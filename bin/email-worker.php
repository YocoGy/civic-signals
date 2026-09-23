<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Services\EmailQueueService;
use App\Services\SmtpMailer;

const VALIDATION_INTERVAL_SECONDS = 15 * 60;
const MUNICIPALITY_INTERVAL_SECONDS = 30 * 60;

$workerId = gethostname() . ':' . getmypid();

$pdo = Connection::database();
$mailer = new SmtpMailer();

$mode = in_array('--daemon', $argv ?? [], true)
    ? 'DAEMON'
    : 'ONCE';

echo "Email worker started.\n";
echo "Worker ID: {$workerId}\n";
echo "Mode: {$mode}\n";

if ($mode === 'DAEMON') {
    echo "Daemon intervals:\n";
    echo "- Validation: 15 minutes\n";
    echo "- Municipality dispatch: 30 minutes\n";
}

/**
 * Process validation emails.
 */
function processValidationQueue(
    PDO $pdo,
    SmtpMailer $mailer
): void {
    echo "Checking validation email queue...\n";

    $stmt = $pdo->prepare(
        "SELECT id
         FROM email_queue
         WHERE purpose = 'validation'
           AND state = 'pending'
           AND available_at <= NOW()
         ORDER BY id ASC
         LIMIT 10"
    );

    $stmt->execute();

    $queueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$queueIds) {
        echo "No pending validation emails.\n";
        echo "Validation processed: 0\n";
        echo "Validation failed: 0\n";
        return;
    }

    echo "Found " . count($queueIds) .
        " pending email(s) for validation.\n";

    $processed = 0;
    $failed = 0;

    foreach ($queueIds as $queueId) {
        $queueId = (int)$queueId;

        echo "Leasing email_queue #{$queueId}\n";

        $success = EmailQueueService::processOne(
            $pdo,
            $queueId,
            $mailer
        );

        if ($success) {
            echo "Sent successfully.\n";

            $messageStmt = $pdo->prepare(
                "SELECT provider_message_id
                 FROM email_queue
                 WHERE id = :id"
            );

            $messageStmt->execute([
                'id' => $queueId,
            ]);

            $messageId = $messageStmt->fetchColumn();

            if ($messageId !== false && $messageId !== null) {
                echo "Message-ID: {$messageId}\n";
            }

            echo "Queue #{$queueId} marked as sent.\n";

            $processed++;
        } else {
            $failed++;

            $errorStmt = $pdo->prepare(
                "SELECT
                    state,
                    last_error_summary
                 FROM email_queue
                 WHERE id = :id"
            );

            $errorStmt->execute([
                'id' => $queueId,
            ]);

            $result = $errorStmt->fetch(PDO::FETCH_ASSOC);

            $state = (string)($result['state'] ?? 'unknown');
            $error = (string)(
                $result['last_error_summary'] ?? ''
            );

            if ($error !== '') {
                echo "Email #{$queueId} failed: {$error}\n";
            } else {
                echo "Email #{$queueId} was not sent. State: {$state}\n";
            }
        }
    }

    echo "Validation processed: {$processed}\n";
    echo "Validation failed: {$failed}\n";
}

/**
 * Process municipality dispatch emails.
 */
function processMunicipalityQueue(
    PDO $pdo,
    SmtpMailer $mailer
): void {
    echo "Checking municipality dispatch queue...\n";

    $stmt = $pdo->prepare(
        "SELECT id
         FROM email_queue
         WHERE purpose = 'municipality_dispatch'
           AND state = 'pending'
           AND available_at <= NOW()
         ORDER BY id ASC
         LIMIT 10"
    );

    $stmt->execute();

    $queueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$queueIds) {
        echo "No pending municipality_dispatch emails.\n";
        echo "Municipality processed: 0\n";
        echo "Municipality failed: 0\n";
        return;
    }

    echo "Found " . count($queueIds) .
        " pending email(s) for municipality_dispatch.\n";

    $processed = 0;
    $failed = 0;

    foreach ($queueIds as $queueId) {
        $queueId = (int)$queueId;

        echo "Leasing email_queue #{$queueId}\n";

        $success = EmailQueueService::processOne(
            $pdo,
            $queueId,
            $mailer
        );

        if ($success) {
            echo "Sent successfully.\n";

            $messageStmt = $pdo->prepare(
                "SELECT provider_message_id
                 FROM email_queue
                 WHERE id = :id"
            );

            $messageStmt->execute([
                'id' => $queueId,
            ]);

            $messageId = $messageStmt->fetchColumn();

            if ($messageId !== false && $messageId !== null) {
                echo "Message-ID: {$messageId}\n";
            }

            echo "Queue #{$queueId} marked as sent.\n";

            /*
             * Municipality emails complete the signal dispatch.
             *
             * The queue item must be sent successfully before
             * the signal is moved from READY_FOR_DISPATCH to SENT.
             */
            $signalStmt = $pdo->prepare(
                "SELECT
                    s.id,
                    s.signal_status_id,
                    s.public_reference,
                    s.recipient_email_snapshot
                 FROM signals s
                 INNER JOIN email_queue q
                    ON q.signal_id = s.id
                 WHERE q.id = :queue_id
                   AND q.purpose = 'municipality_dispatch'
                 LIMIT 1"
            );

            $signalStmt->execute([
                'queue_id' => $queueId,
            ]);

            $signal = $signalStmt->fetch(PDO::FETCH_ASSOC);

            if ($signal) {
                $sentStatusStmt = $pdo->prepare(
                    "SELECT id
                     FROM signal_statuses
                     WHERE code = 'SENT'
                       AND is_active = 1
                     LIMIT 1"
                );

                $sentStatusStmt->execute();

                $sentStatusId = (int)$sentStatusStmt->fetchColumn();

                if (!$sentStatusId) {
                    throw new RuntimeException(
                        'SENT status is not configured.'
                    );
                }

                $previousStatusId =
                    (int)$signal['signal_status_id'];

                /*
                 * Only move the signal to SENT when it is still
                 * READY_FOR_DISPATCH.
                 */
                if ($previousStatusId === 3) {
                    $updateSignal = $pdo->prepare(
                        "UPDATE signals
                         SET
                            signal_status_id = :new_status,
                            dispatched_at = NOW(),
                            updated_at = NOW()
                         WHERE id = :signal_id
                           AND signal_status_id = :previous_status"
                    );

                    $updateSignal->execute([
                        'new_status'      => $sentStatusId,
                        'signal_id'       => (int)$signal['id'],
                        'previous_status' => $previousStatusId,
                    ]);

                    if ($updateSignal->rowCount() !== 1) {
                        throw new RuntimeException(
                            "Signal #{$signal['id']} could not be marked as SENT."
                        );
                    }

                    $metadata = json_encode(
                        [
                            'queue_id' => $queueId,
                            'recipient_email' =>
                                $signal['recipient_email_snapshot'],
                            'recovery' => true,
                        ],
                        JSON_THROW_ON_ERROR
                    );

                    $event = $pdo->prepare(
                        "INSERT INTO signal_events (
                            signal_id,
                            actor_user_id,
                            event_code,
                            previous_status_id,
                            new_status_id,
                            metadata
                         ) VALUES (
                            :signal_id,
                            NULL,
                            'signal.dispatched',
                            :previous_status,
                            :new_status,
                            :metadata
                         )"
                    );

                    $event->execute([
                        'signal_id' =>
                            (int)$signal['id'],
                        'previous_status' =>
                            $previousStatusId,
                        'new_status' =>
                            $sentStatusId,
                        'metadata' =>
                            $metadata,
                    ]);

                    echo "Signal #{$signal['id']} marked as SENT.\n";
                }
            }

            $processed++;
        } else {
            $failed++;

            $errorStmt = $pdo->prepare(
                "SELECT
                    state,
                    last_error_summary
                 FROM email_queue
                 WHERE id = :id"
            );

            $errorStmt->execute([
                'id' => $queueId,
            ]);

            $result = $errorStmt->fetch(PDO::FETCH_ASSOC);

            $state = (string)($result['state'] ?? 'unknown');
            $error = (string)(
                $result['last_error_summary'] ?? ''
            );

            if ($error !== '') {
                echo "Email #{$queueId} failed: {$error}\n";
            } else {
                echo "Email #{$queueId} was not sent. State: {$state}\n";
            }
        }
    }

    echo "Municipality processed: {$processed}\n";
    echo "Municipality failed: {$failed}\n";
}


/*
 * ONCE mode:
 *
 * Used for manual maintenance/testing.
 */
if ($mode === 'ONCE') {
    processValidationQueue($pdo, $mailer);
    processMunicipalityQueue($pdo, $mailer);

    exit(0);
}


/*
 * DAEMON mode.
 *
 * Validation is checked every 15 minutes.
 * Municipality dispatch is checked every 30 minutes.
 *
 * The first check happens immediately.
 */
echo "Initial queue check...\n";

processValidationQueue($pdo, $mailer);
processMunicipalityQueue($pdo, $mailer);

$lastValidationCheck = time();
$lastMunicipalityCheck = time();

while (true) {
    sleep(1);

    $now = time();

    if (
        ($now - $lastValidationCheck) >=
        VALIDATION_INTERVAL_SECONDS
    ) {
        echo "\n[" .
            date('Y-m-d H:i:s') .
            "] ";

        processValidationQueue($pdo, $mailer);

        $lastValidationCheck = $now;
    }

    if (
        ($now - $lastMunicipalityCheck) >=
        MUNICIPALITY_INTERVAL_SECONDS
    ) {
        echo "\n[" .
            date('Y-m-d H:i:s') .
            "] ";

        processMunicipalityQueue($pdo, $mailer);

        $lastMunicipalityCheck = $now;
    }
}