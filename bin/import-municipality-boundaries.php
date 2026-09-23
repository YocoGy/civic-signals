<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
use App\Database\Connection;

const DATASET_NAME = 'SU_BG_NSI_LAU_2024_1';
const DATASET_VERSION = '2024.1';
const EXPECTED_ENTITIES = 265;
const SOURCE_EPSG = 9391;

$relative = app_env('GEOGRAPHY_SOURCE', 'data/geography/SU_BG_NSI_LAU_2024_1.gpkg');
$source = APP_ROOT . '/' . ltrim($relative, '/\\');
if (!is_file($source)) throw new RuntimeException("GeoPackage not found: {$source}");
$sqlite = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$layer = $sqlite->query("SELECT table_name FROM gpkg_contents WHERE data_type = 'features' AND srs_id = " . SOURCE_EPSG)->fetchColumn();
if ($layer !== 'Obshtini_2024_1') throw new RuntimeException('Expected municipality layer Obshtini_2024_1 in EPSG:9391.');
$count = (int) $sqlite->query('SELECT COUNT(*) FROM "' . $layer . '"')->fetchColumn();
if ($count !== EXPECTED_ENTITIES) throw new RuntimeException("Expected " . EXPECTED_ENTITIES . " features, found {$count}.");
$pdo = Connection::database();
$hash = hash_file('sha256', $source);
$pdo->beginTransaction();
try {
    $dataset = $pdo->prepare('INSERT INTO geography_datasets (dataset_name,version,reference_date,source_name,source_file,source_sha256,crs_epsg,identifier_scheme,expected_entities,imported_at) VALUES (:name,:version,:reference_date,:source_name,:source_file,:hash,:epsg,:identifier,:entities,NOW()) ON DUPLICATE KEY UPDATE source_sha256=VALUES(source_sha256), source_file=VALUES(source_file), crs_epsg=VALUES(crs_epsg), expected_entities=VALUES(expected_entities), imported_at=NOW(), id=LAST_INSERT_ID(id)');
    $dataset->execute(['name'=>DATASET_NAME,'version'=>DATASET_VERSION,'reference_date'=>'2024-12-31','source_name'=>'National Statistical Institute (NSI)','source_file'=>$relative,'hash'=>$hash,'epsg'=>SOURCE_EPSG,'identifier'=>'EKATTE','entities'=>EXPECTED_ENTITIES]);
    $datasetId = (int)$pdo->lastInsertId();
    $pdo->prepare('DELETE FROM municipality_boundaries WHERE geography_dataset_id = :dataset')->execute(['dataset'=>$datasetId]);
    $features = $sqlite->query('SELECT "Identifier","Name","NameLatin","DistrictCode","NUTS3v2024","NUTS3v2027","Shape" FROM "' . $layer . '"');
    $municipality = $pdo->prepare('INSERT INTO municipalities (ekatte_code,name,name_latin,district_code,nuts3_2024,nuts3_2027,active) VALUES (:code,:name,:latin,:district,:nuts2024,:nuts2027,1) ON DUPLICATE KEY UPDATE name=VALUES(name),name_latin=VALUES(name_latin),district_code=VALUES(district_code),nuts3_2024=VALUES(nuts3_2024),nuts3_2027=VALUES(nuts3_2027),active=1,deleted_at=NULL,id=LAST_INSERT_ID(id)');
    $boundary = $pdo->prepare('INSERT INTO municipality_boundaries (municipality_id,geography_dataset_id,geometry,source_feature_identifier) VALUES (:municipality,:dataset,ST_GeomFromWKB(UNHEX(:geometry),' . SOURCE_EPSG . '),:code)');
    $seen = [];
    foreach ($features as $row) {
        $code = trim((string)$row['Identifier']);
        if ($code === '' || isset($seen[$code])) throw new RuntimeException('Missing or duplicate EKATTE identifier in GeoPackage.');
        $seen[$code] = true;
        $wkb = gpkg_wkb((string)$row['Shape']);
        $municipality->execute(['code'=>$code,'name'=>$row['Name'],'latin'=>$row['NameLatin'],'district'=>$row['DistrictCode'],'nuts2024'=>$row['NUTS3v2024'],'nuts2027'=>$row['NUTS3v2027']]);
        $boundary->execute(['municipality'=>(int)$pdo->lastInsertId(),'dataset'=>$datasetId,'geometry'=>bin2hex($wkb),'code'=>$code]);
    }
    if (count($seen) !== EXPECTED_ENTITIES) throw new RuntimeException('Imported municipality identifier count is invalid.');
    $check = (int)$pdo->query("SELECT COUNT(*) FROM municipality_boundaries WHERE geography_dataset_id = {$datasetId}")->fetchColumn();
    // MariaDB 10.4 has no ST_IsValid. WKB parsing is performed by MariaDB on import;
    // verify every stored value retains the authoritative MULTIPOLYGON type and SRID.
    $invalid = (int)$pdo->query("SELECT COUNT(*) FROM municipality_boundaries WHERE geography_dataset_id = {$datasetId} AND (ST_GeometryType(geometry) <> 'MULTIPOLYGON' OR ST_SRID(geometry) <> " . SOURCE_EPSG . ")")->fetchColumn();
    if ($check !== EXPECTED_ENTITIES || $invalid !== 0) throw new RuntimeException("Boundary validation failed: count={$check}, invalid={$invalid}");
    $pdo->commit(); echo "IMPORTED municipalities={$check}, dataset=" . DATASET_NAME . '@' . DATASET_VERSION . ', crs=EPSG:' . SOURCE_EPSG . PHP_EOL;
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

/** Extract OGC WKB from a GeoPackage binary geometry header. */
function gpkg_wkb(string $binary): string {
    if (strlen($binary) < 13 || substr($binary, 0, 2) !== 'GP') throw new RuntimeException('Invalid GeoPackage geometry header.');
    $flags = ord($binary[3]); $envelope = ($flags >> 1) & 0x07;
    $lengths = [0=>0, 1=>32, 2=>48, 3=>48, 4=>64];
    if (!array_key_exists($envelope, $lengths)) throw new RuntimeException('Unsupported GeoPackage envelope.');
    $wkb = substr($binary, 8 + $lengths[$envelope]);
    if (strlen($wkb) < 5 || ord($wkb[0]) > 1) throw new RuntimeException('Invalid WKB payload.');
    $type = unpack(ord($wkb[0]) === 1 ? 'Vtype' : 'Ntype', substr($wkb, 1, 4))['type'] & 0xff;
    if ($type !== 6) throw new RuntimeException('Expected MULTIPOLYGON geometry.');
    return $wkb;
}
