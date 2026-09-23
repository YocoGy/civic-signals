<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class MunicipalityLocator
{
    /**
     * Finds the municipality containing the supplied WGS84 coordinates.
     *
     * Input:
     *   latitude  = WGS84 latitude
     *   longitude = WGS84 longitude
     *
     * Database geometry:
     *   EPSG:9391 / BGS2005 UTM zone 35N
     *
     * @return array{
     *     municipality_id:int,
     *     ekatte_code:string,
     *     name:string,
     *     district_code:?string
     * }
     */
    public static function locate(
        PDO $pdo,
        float $latitude,
        float $longitude
    ): array {
        if (!is_finite($latitude) || !is_finite($longitude)) {
            throw new RuntimeException(
                'Invalid geographic coordinates.'
            );
        }

        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new RuntimeException(
                'Latitude is outside the valid range.'
            );
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new RuntimeException(
                'Longitude is outside the valid range.'
            );
        }

        /*
         * Convert WGS84 coordinates from the signal/photo
         * to BGS2005 / UTM zone 35N (EPSG:9391).
         */
        $point = self::wgs84ToBgs2005Utm35(
            $longitude,
            $latitude
        );

        $wkt = sprintf(
            'POINT(%.6f %.6f)',
            $point['easting'],
            $point['northing']
        );

        $sql = <<<'SQL'
SELECT
    mb.municipality_id,
    m.ekatte_code,
    m.name,
    m.district_code
FROM municipality_boundaries mb
INNER JOIN municipalities m
    ON m.id = mb.municipality_id
WHERE m.active = 1
  AND m.deleted_at IS NULL
  AND ST_Contains(
      mb.geometry,
      ST_GeomFromText(:point, 9391)
  )
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'point' => $wkt,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            throw new RuntimeException(
                'The signal coordinates are not inside any active municipality boundary.'
            );
        }

        if (count($rows) > 1) {
            throw new RuntimeException(
                'The signal coordinates intersect multiple municipality boundaries.'
            );
        }

        $row = $rows[0];

        return [
            'municipality_id' => (int) $row['municipality_id'],
            'ekatte_code'     => (string) $row['ekatte_code'],
            'name'            => (string) $row['name'],
            'district_code'   => $row['district_code'] !== null
                ? (string) $row['district_code']
                : null,
        ];
    }

    /**
     * WGS84 (EPSG:4326) -> BGS2005 / UTM zone 35N (EPSG:9391).
     *
     * No external libraries are required.
     *
     * @return array{easting:float,northing:float}
     */
    private static function wgs84ToBgs2005Utm35(
        float $longitude,
        float $latitude
    ): array {
        if ($longitude < 24.0 || $longitude > 30.0) {
            throw new RuntimeException(
                'Longitude is outside the EPSG:9391 area of use.'
            );
        }

        if ($latitude < 41.0 || $latitude > 45.0) {
            throw new RuntimeException(
                'Latitude is outside the EPSG:9391 area of use.'
            );
        }

        /*
         * GRS80 ellipsoid.
         */
        $a = 6378137.0;
        $inverseFlattening = 298.257222101;
        $f = 1.0 / $inverseFlattening;

        $e2 = $f * (2.0 - $f);
        $ep2 = $e2 / (1.0 - $e2);

        /*
         * EPSG:9391 parameters.
         */
        $k0 = 0.9996;
        $centralMeridian = 27.0;
        $falseEasting = 500000.0;
        $falseNorthing = 0.0;

        $lat = deg2rad($latitude);
        $lon = deg2rad($longitude);
        $lon0 = deg2rad($centralMeridian);

        $sinLat = sin($lat);
        $cosLat = cos($lat);
        $tanLat = tan($lat);

        $n = $a / sqrt(
            1.0 - $e2 * $sinLat * $sinLat
        );

        $t = $tanLat * $tanLat;
        $c = $ep2 * $cosLat * $cosLat;
        $a1 = $cosLat * ($lon - $lon0);

        /*
         * Meridian arc.
         */
        $m = $a * (
            (
                1.0
                - $e2 / 4.0
                - 3.0 * $e2 ** 2 / 64.0
                - 5.0 * $e2 ** 3 / 256.0
            ) * $lat

            - (
                3.0 * $e2 / 8.0
                + 3.0 * $e2 ** 2 / 32.0
                + 45.0 * $e2 ** 3 / 1024.0
            ) * sin(2.0 * $lat)

            + (
                15.0 * $e2 ** 2 / 256.0
                + 45.0 * $e2 ** 3 / 1024.0
            ) * sin(4.0 * $lat)

            - (
                35.0 * $e2 ** 3 / 3072.0
            ) * sin(6.0 * $lat)
        );

        $easting = $falseEasting + $k0 * $n * (
            $a1
            + (1.0 - $t + $c) * $a1 ** 3 / 6.0
            + (
                5.0
                - 18.0 * $t
                + $t ** 2
                + 72.0 * $c
                - 58.0 * $ep2
            ) * $a1 ** 5 / 120.0
        );

        $northing = $falseNorthing + $k0 * (
            $m
            + $n * $tanLat * (
                $a1 ** 2 / 2.0
                + (
                    5.0
                    - $t
                    + 9.0 * $c
                    + 4.0 * $c ** 2
                ) * $a1 ** 4 / 24.0
                + (
                    61.0
                    - 58.0 * $t
                    + $t ** 2
                    + 600.0 * $c
                    - 330.0 * $ep2
                ) * $a1 ** 6 / 720.0
            )
        );

        return [
            'easting'  => $easting,
            'northing' => $northing,
        ];
    }
}