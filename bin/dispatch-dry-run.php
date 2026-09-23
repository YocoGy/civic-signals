<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Services\MunicipalityContactResolver;
use App\Services\MunicipalityLocator;

try {
    $pdo = Connection::database();

    $signalId = isset($argv[1])
    ? (int)$argv[1]
    : 0;

	if ($signalId < 1) {
		throw new RuntimeException(
			'Usage: php dispatch-dry-run.php <signal_id>'
		);
	}

    /*
     * ---------------------------------------------------------
     * 1. Load signal
     * ---------------------------------------------------------
     */
    $stmt = $pdo->prepare(
        <<<'SQL'
SELECT
    id,
    public_reference,
    reporter_email,
    signal_status_id,
    signal_category_id,
    municipality_id,
    municipality_contact_id,
    municipality_name_snapshot,
    recipient_email_snapshot,
    latitude,
    longitude,
    gps_source,
    captured_at_local,
    email_validated_at,
    dispatch_prepared_at,
    dispatched_at
FROM signals
WHERE id = :id
LIMIT 1
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

    echo "=== DISPATCH DRY-RUN ===\n\n";

    echo "Signal ID       : {$signal['id']}\n";
    echo "Reference       : {$signal['public_reference']}\n";
    echo "Reporter email  : {$signal['reporter_email']}\n";
    echo "Status ID       : {$signal['signal_status_id']}\n";
    echo "Category ID     : " .
        ($signal['signal_category_id'] ?? 'NULL') . "\n";
    echo "Latitude        : {$signal['latitude']}\n";
    echo "Longitude       : {$signal['longitude']}\n";
    echo "GPS source      : {$signal['gps_source']}\n\n";

    /*
     * ---------------------------------------------------------
     * 2. Validate current state
     * ---------------------------------------------------------
     */
    if ((int) $signal['signal_status_id'] !== 2) {
        throw new RuntimeException(
            'Signal is not in EMAIL_VALIDATED status.'
        );
    }

    if ($signal['email_validated_at'] === null) {
        throw new RuntimeException(
            'Signal has no email_validated_at timestamp.'
        );
    }

    /*
     * ---------------------------------------------------------
     * 3. Resolve municipality from GPS
     * ---------------------------------------------------------
     */
    $municipality = MunicipalityLocator::locate(
        $pdo,
        (float) $signal['latitude'],
        (float) $signal['longitude']
    );

    echo "=== MUNICIPALITY ===\n\n";

    echo "Municipality ID : {$municipality['municipality_id']}\n";
    echo "EKATTE code     : {$municipality['ekatte_code']}\n";
    echo "Name            : {$municipality['name']}\n";
    echo "District code   : {$municipality['district_code']}\n\n";

    /*
     * ---------------------------------------------------------
     * 4. Resolve municipality contact
     * ---------------------------------------------------------
     */
    $contact = MunicipalityContactResolver::resolve(
        $pdo,
        $municipality['municipality_id'],
        $signal['signal_category_id'] !== null
            ? (int) $signal['signal_category_id']
            : null
    );

    echo "=== RECIPIENT ===\n\n";

    echo "Contact ID      : {$contact['id']}\n";
    echo "Contact type    : {$contact['contact_type']}\n";
    echo "Contact name    : " .
        ($contact['contact_name'] ?? 'NULL') . "\n";
    echo "Email           : {$contact['email']}\n";
    echo "Priority        : {$contact['priority']}\n\n";

    /*
     * ---------------------------------------------------------
     * 5. Show what WOULD be written
     * ---------------------------------------------------------
     */
    echo "=== WOULD UPDATE signals ===\n\n";

    echo "municipality_id            = "
        . $municipality['municipality_id'] . "\n";

    echo "municipality_contact_id    = "
        . $contact['id'] . "\n";

    echo "municipality_name_snapshot = "
        . $municipality['name'] . "\n";

    echo "recipient_email_snapshot  = "
        . $contact['email'] . "\n";

    echo "dispatch_prepared_at      = NOW()\n\n";

    /*
     * ---------------------------------------------------------
     * 6. Show what WOULD be queued
     * ---------------------------------------------------------
     */
    echo "=== WOULD INSERT email_queue ===\n\n";

    echo "signal_id                 = "
        . $signal['id'] . "\n";

    echo "municipality_contact_id   = "
        . $contact['id'] . "\n";

    echo "purpose                   = municipality_dispatch\n";

    echo "recipient_email           = "
        . $contact['email'] . "\n";

    echo "template_code             = signal_municipality_dispatch\n";

    echo "\n";

    /*
     * ---------------------------------------------------------
     * 7. Explicitly confirm no database changes
     * ---------------------------------------------------------
     */
    echo "=== RESULT ===\n\n";
    echo "DRY-RUN SUCCESS.\n";
    echo "No database changes were made.\n";
    echo "No email was queued.\n";
    echo "No email was sent.\n";

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "ERROR: " . $e->getMessage() . PHP_EOL
    );

    exit(1);
}