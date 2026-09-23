<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class MunicipalityDispatchService
{
    /**
     * Prepare a validated signal for dispatch to the municipality.
     *
     * This method is intended to run inside an existing transaction.
     *
     * It:
     *  - verifies EMAIL_VALIDATED state
     *  - resolves municipality from GPS
     *  - resolves the municipality recipient
     *  - stores immutable dispatch snapshots
     *  - moves the signal to READY_FOR_DISPATCH
     *  - creates the municipality_dispatch email queue record
     *  - records the state transition
     */
    public static function prepare(
        PDO $pdo,
        int $signalId
    ): array {
        if ($signalId < 1) {
            throw new RuntimeException(
                'Invalid signal ID.'
            );
        }

        /*
         * Load the signal while keeping it locked.
         */
        $stmt = $pdo->prepare(
            <<<'SQL'
SELECT
    id,
    public_reference,
    signal_status_id,
    signal_category_id,
    latitude,
    longitude,
    reporter_email,
    reporter_contact_allowed,
    reporter_contact_allowed_at,
    reporter_contact_policy_version,
    email_validated_at,
    municipality_id,
    municipality_contact_id,
    municipality_name_snapshot,
    recipient_email_snapshot,
    dispatch_prepared_at
FROM signals
WHERE id = :id
LIMIT 1
FOR UPDATE
SQL
        );

        $stmt->execute([
            'id' => $signalId,
        ]);

        $signal = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($signal === false) {
            throw new RuntimeException(
                "Signal #{$signalId} was not found."
            );
        }

        /*
         * Already prepared?
         *
         * This makes the operation idempotent.
         */
        if (
            (int) $signal['signal_status_id'] === 3
            && $signal['dispatch_prepared_at'] !== null
            && $signal['municipality_id'] !== null
            && $signal['municipality_contact_id'] !== null
        ) {
            $municipality = [
                'municipality_id' =>
                    (int) $signal['municipality_id'],

                'ekatte_code' => '',

                'name' =>
                    (string) $signal['municipality_name_snapshot'],

                'district_code' => null,
            ];

            $contactStmt = $pdo->prepare(
                <<<'SQL'
SELECT
    id,
    municipality_id,
    signal_category_id,
    contact_type,
    contact_name,
    email,
    priority
FROM municipality_contacts
WHERE id = :id
LIMIT 1
SQL
            );

            $contactStmt->execute([
                'id' =>
                    (int) $signal['municipality_contact_id'],
            ]);

            $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);

            if ($contact === false) {
                throw new RuntimeException(
                    'Prepared municipality contact no longer exists.'
                );
            }

            return [
                'municipality' =>
                    $municipality,

                'contact' =>
                    self::normalizeContact($contact),

                'queue_id' =>
                    self::findQueueId(
                        $pdo,
                        $signalId
                    ),
            ];
        }

        if (
            (int) $signal['signal_status_id'] !== 2
        ) {
            throw new RuntimeException(
                'Signal is not in EMAIL_VALIDATED status.'
            );
        }

        if (
            $signal['email_validated_at'] === null
        ) {
            throw new RuntimeException(
                'Signal has no email_validated_at timestamp.'
            );
        }

        /*
         * GPS coordinates must exist because the municipality
         * is determined from the original EXIF GPS data.
         */
        if (
            $signal['latitude'] === null ||
            $signal['longitude'] === null
        ) {
            throw new RuntimeException(
                'Signal has no GPS coordinates.'
            );
        }

        /*
         * Resolve municipality from the original GPS coordinates.
         */
        $municipality = MunicipalityLocator::locate(
            $pdo,
            (float) $signal['latitude'],
            (float) $signal['longitude']
        );

        /*
         * Resolve the best active municipality recipient.
         */
        $contact = MunicipalityContactResolver::resolve(
            $pdo,
            $municipality['municipality_id'],
            $signal['signal_category_id'] !== null
                ? (int) $signal['signal_category_id']
                : null
        );

        /*
         * Determine whether the reporter has explicitly allowed
         * the municipality to contact them by email.
         */
        $reporterContactAllowed =
            (int) $signal['reporter_contact_allowed'] === 1;

        /*
         * Build the municipality dispatch payload.
         *
         * GPS coordinates are always included.
         *
         * The reporter's email is included ONLY when the reporter
         * explicitly granted contact permission.
         */
        $payloadData = [
            'signal_reference' =>
                (string) $signal['public_reference'],

            'municipality_id' =>
                $municipality['municipality_id'],

            'municipality_name' =>
                $municipality['name'],

            'ekatte_code' =>
                $municipality['ekatte_code'],

            'district_code' =>
                $municipality['district_code'],

            'latitude' =>
                number_format(
                    (float) $signal['latitude'],
                    7,
                    '.',
                    ''
                ),

            'longitude' =>
                number_format(
                    (float) $signal['longitude'],
                    7,
                    '.',
                    ''
                ),

            'reporter_contact_allowed' =>
                $reporterContactAllowed,
        ];

        /*
         * Do not place the reporter's email in the payload unless
         * explicit permission was granted.
         */
        if ($reporterContactAllowed) {
            $reporterEmail =
                trim(
                    (string) $signal['reporter_email']
                );

            if ($reporterEmail !== '') {
                $payloadData['reporter_email'] =
                    $reporterEmail;
            }
        }

        $payload = json_encode(
            $payloadData,
            JSON_THROW_ON_ERROR
        );

        $recipientHash = hash(
            'sha256',
            strtolower(
                trim(
                    $contact['email']
                )
            )
        );

        $idempotencyKey = hash(
            'sha256',
            'municipality_dispatch:' .
            $signalId
        );

        /*
         * Store immutable dispatch snapshots on the signal.
         */
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE signals
SET
    municipality_id = :municipality_id,
    municipality_contact_id = :contact_id,
    municipality_name_snapshot = :municipality_name,
    recipient_email_snapshot = :recipient_email,
    dispatch_prepared_at = NOW(),
    signal_status_id = :new_status,
    updated_at = NOW()
WHERE id = :signal_id
  AND signal_status_id = :previous_status
SQL
        );

        $update->execute([
            'municipality_id' =>
                $municipality['municipality_id'],

            'contact_id' =>
                $contact['id'],

            'municipality_name' =>
                $municipality['name'],

            'recipient_email' =>
                $contact['email'],

            'new_status' =>
                3,

            'signal_id' =>
                $signalId,

            'previous_status' =>
                2,
        ]);

        if (
            $update->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Signal could not be moved to READY_FOR_DISPATCH.'
            );
        }

        /*
         * Record state transition.
         */
        $event = $pdo->prepare(
            <<<'SQL'
INSERT INTO signal_events
(
    signal_id,
    actor_user_id,
    event_code,
    previous_status_id,
    new_status_id,
    metadata
)
VALUES
(
    :signal_id,
    NULL,
    'signal.dispatch.prepared',
    :previous_status,
    :new_status,
    :metadata
)
SQL
        );

        $event->execute([
            'signal_id' =>
                $signalId,

            'previous_status' =>
                2,

            'new_status' =>
                3,

            'metadata' =>
                json_encode(
                    [
                        'municipality_id' =>
                            $municipality['municipality_id'],

                        'municipality_contact_id' =>
                            $contact['id'],

                        'recipient_email' =>
                            $contact['email'],

                        'reporter_contact_allowed' =>
                            $reporterContactAllowed,
                    ],
                    JSON_THROW_ON_ERROR
                ),
        ]);

        /*
         * Queue the municipality email.
         *
         * The unique idempotency key protects against duplicate
         * dispatch queue entries.
         */
        $queue = $pdo->prepare(
            <<<'SQL'
INSERT INTO email_queue
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
    :signal_id,
    :contact_id,
    'municipality_dispatch',
    :recipient_email,
    :recipient_hash,
    'signal_municipality_dispatch',
    :payload,
    'pending',
    :idempotency_key,
    0,
    NOW()
)
SQL
        );

        try {
            $queue->execute([
                'signal_id' =>
                    $signalId,

                'contact_id' =>
                    $contact['id'],

                'recipient_email' =>
                    $contact['email'],

                'recipient_hash' =>
                    $recipientHash,

                'payload' =>
                    $payload,

                'idempotency_key' =>
                    $idempotencyKey,
            ]);

        } catch (\PDOException $e) {

            /*
             * A duplicate idempotency key means the dispatch was
             * already queued. Do not create another message.
             */
            if (
                (string) $e->getCode() !== '23000'
            ) {
                throw $e;
            }
        }

        $queueId = self::findQueueId(
            $pdo,
            $signalId
        );

        return [
            'municipality' =>
                $municipality,

            'contact' =>
                self::normalizeContact(
                    $contact
                ),

            'queue_id' =>
                $queueId,
        ];
    }

    /**
     * @return array{
     *     id:int,
     *     municipality_id:int,
     *     signal_category_id:?int,
     *     contact_type:string,
     *     contact_name:?string,
     *     email:string,
     *     priority:int
     * }
     */
    private static function normalizeContact(
        array $contact
    ): array {
        return [
            'id' =>
                (int) $contact['id'],

            'municipality_id' =>
                (int) $contact['municipality_id'],

            'signal_category_id' =>
                $contact['signal_category_id'] !== null
                    ? (int) $contact['signal_category_id']
                    : null,

            'contact_type' =>
                (string) $contact['contact_type'],

            'contact_name' =>
                $contact['contact_name'] !== null
                    ? (string) $contact['contact_name']
                    : null,

            'email' =>
                (string) $contact['email'],

            'priority' =>
                (int) $contact['priority'],
        ];
    }

    private static function findQueueId(
        PDO $pdo,
        int $signalId
    ): int {
        $stmt = $pdo->prepare(
            <<<'SQL'
SELECT id
FROM email_queue
WHERE signal_id = :signal_id
  AND purpose = 'municipality_dispatch'
ORDER BY id DESC
LIMIT 1
SQL
        );

        $stmt->execute([
            'signal_id' =>
                $signalId,
        ]);

        $id =
            $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                'Municipality dispatch queue record could not be found.'
            );
        }

        return (int) $id;
    }
}