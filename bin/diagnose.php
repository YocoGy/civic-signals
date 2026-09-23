<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use App\Database\Connection;

$failed = false;
function check_line(string $name, bool $ok, string $value = ''): void { global $failed; echo $name . ': ' . ($ok ? 'OK' : 'FAIL') . ($value !== '' ? " ({$value})" : '') . PHP_EOL; if (!$ok) $failed = true; }
try {
    $pdo = Connection::database(); check_line('Application', true); check_line('Database', (bool)$pdo->query('SELECT 1')->fetchColumn());
    $required = ['schema_migrations','users','roles','permissions','user_roles','role_permissions','signals','signal_photos','signal_email_validations','signal_events','signal_statuses','signal_categories','municipalities','municipality_contacts','geography_datasets','municipality_boundaries','email_queue','audit_log','login_attempts','public_submission_attempts','settings'];
    $existing = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    $migrationCount = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    check_line('Migrations', count(array_diff($required, $existing)) === 0 && $migrationCount >= 1, "applied={$migrationCount}");
    check_line('Seed', (int)$pdo->query('SELECT COUNT(*) FROM signal_statuses')->fetchColumn() === 8);
    $foreignKeys = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()")->fetchColumn(); check_line('Foreign keys', $foreignKeys >= 15, "count={$foreignKeys}");
    $municipalities = (int)$pdo->query('SELECT COUNT(*) FROM municipalities WHERE deleted_at IS NULL')->fetchColumn(); check_line('Municipalities', $municipalities === 265, (string)$municipalities);
    $duplicate = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT ekatte_code FROM municipalities GROUP BY ekatte_code HAVING COUNT(*) > 1) x')->fetchColumn(); check_line('Unique EKATTE', $duplicate === 0, "duplicates={$duplicate}");
    $boundaries = (int)$pdo->query('SELECT COUNT(*) FROM municipality_boundaries')->fetchColumn(); check_line('Boundary geometries', $boundaries === 265, (string)$boundaries);
    $dataset = $pdo->query('SELECT dataset_name,version,crs_epsg FROM geography_datasets ORDER BY imported_at DESC LIMIT 1')->fetch(); check_line('Dataset', $dataset !== false, $dataset ? $dataset['dataset_name'] : 'missing'); check_line('Dataset version', $dataset && $dataset['version'] === '2024.1', $dataset['version'] ?? 'missing'); check_line('CRS', $dataset && (int)$dataset['crs_epsg'] === 9391, $dataset ? 'EPSG:' . $dataset['crs_epsg'] : 'missing');
    // MariaDB 10.4 has no ST_IsValid; the compatible import integrity check is type + SRID.
    $invalid = (int)$pdo->query("SELECT COUNT(*) FROM municipality_boundaries WHERE ST_GeometryType(geometry) <> 'MULTIPOLYGON' OR ST_SRID(geometry) <> 9391")->fetchColumn(); check_line('Invalid geometries', $invalid === 0, (string)$invalid);
    check_line('Security configuration', app_env('APP_KEY') !== null && strlen((string)app_env('APP_KEY')) >= 32, 'APP_KEY must be configured locally');
} catch (Throwable $e) { check_line('Diagnostic', false, $e->getMessage()); }
exit($failed ? 1 : 0);
