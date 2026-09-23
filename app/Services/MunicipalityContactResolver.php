<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class MunicipalityContactResolver
{
    /**
     * Resolve the best active contact for a municipality and signal category.
     *
     * Priority:
     * 1. Contact for municipality + exact signal category
     * 2. General "signals" contact for municipality
     * 3. "fallback" contact for municipality
     *
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
    public static function resolve(
        PDO $pdo,
        int $municipalityId,
        ?int $signalCategoryId
    ): array {
        if ($municipalityId < 1) {
            throw new RuntimeException(
                'Invalid municipality ID.'
            );
        }

        /*
         * 1. Exact category contact.
         *
         * Only used when the signal actually has a category.
         */
        if ($signalCategoryId !== null) {
            $stmt = $pdo->prepare(
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
WHERE municipality_id = :municipality_id
  AND signal_category_id = :signal_category_id
  AND is_active = 1
  AND deleted_at IS NULL
  AND email <> ''
ORDER BY priority ASC, id ASC
LIMIT 1
SQL
            );

            $stmt->execute([
                'municipality_id' => $municipalityId,
                'signal_category_id' => $signalCategoryId,
            ]);

            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact !== false) {
                return self::normalize($contact);
            }
        }

        /*
         * 2. General signals contact.
         */
        $stmt = $pdo->prepare(
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
WHERE municipality_id = :municipality_id
  AND contact_type = 'signals'
  AND signal_category_id IS NULL
  AND is_active = 1
  AND deleted_at IS NULL
  AND email <> ''
ORDER BY priority ASC, id ASC
LIMIT 1
SQL
        );

        $stmt->execute([
            'municipality_id' => $municipalityId,
        ]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact !== false) {
            return self::normalize($contact);
        }

        /*
         * 3. Fallback contact.
         */
        $stmt = $pdo->prepare(
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
WHERE municipality_id = :municipality_id
  AND contact_type = 'fallback'
  AND is_active = 1
  AND deleted_at IS NULL
  AND email <> ''
ORDER BY priority ASC, id ASC
LIMIT 1
SQL
        );

        $stmt->execute([
            'municipality_id' => $municipalityId,
        ]);

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact !== false) {
            return self::normalize($contact);
        }

        throw new RuntimeException(
            'No active municipality contact is available for signal dispatch.'
        );
    }

    /**
     * @param array<string,mixed> $contact
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
    private static function normalize(array $contact): array
    {
        return [
            'id' => (int) $contact['id'],
            'municipality_id' => (int) $contact['municipality_id'],
            'signal_category_id' => $contact['signal_category_id'] !== null
                ? (int) $contact['signal_category_id']
                : null,
            'contact_type' => (string) $contact['contact_type'],
            'contact_name' => $contact['contact_name'] !== null
                ? (string) $contact['contact_name']
                : null,
            'email' => (string) $contact['email'],
            'priority' => (int) $contact['priority'],
        ];
    }
}