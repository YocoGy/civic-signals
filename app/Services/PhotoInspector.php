<?php
declare(strict_types=1);

namespace App\Services;

final class PhotoInspector
{
    public const MAX_BYTES = 20971520;
    public const MAX_PIXELS = 55000000;

    /**
     * @return array{
     *     mime_type:string,
     *     file_size:int,
     *     width:int,
     *     height:int,
     *     sha256_hash:string,
     *     latitude:string,
     *     longitude:string,
     *     captured_at:string,
     *     exif_datetime_original:string
     * }
     */
    public static function inspect(
        string $path,
        string $originalName
    ): array {
        if (
            !is_file($path)
            || filesize($path) === false
            || filesize($path) < 4
        ) {
            throw new \RuntimeException(
                'Каченият файл липсва или е празен.'
            );
        }

        $size = (int) filesize($path);

        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException(
                'Снимката надвишава максимално допустимия размер от 20 MB.'
            );
        }

        $extension = strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );

        if (
            $extension !== 'jpg'
            && $extension !== 'jpeg'
        ) {
            throw new \RuntimeException(
                'Приемат се само JPEG/JPG снимки.'
            );
        }

        $head = file_get_contents(
            $path,
            false,
            null,
            0,
            3
        );

        if ($head !== "\xFF\xD8\xFF") {
            throw new \RuntimeException(
                'Файлът не е валидна JPEG снимка.'
            );
        }

        $finfo = new \finfo(
            FILEINFO_MIME_TYPE
        );

        if (
            $finfo->file($path)
            !== 'image/jpeg'
        ) {
            throw new \RuntimeException(
                'Установеният тип на файла не е JPEG.'
            );
        }

        $image = @getimagesize($path);

        if (
            !is_array($image)
            || ($image[2] ?? null)
                !== IMAGETYPE_JPEG
        ) {
            throw new \RuntimeException(
                'JPEG снимката не може да бъде прочетена.'
            );
        }

        $width = (int) $image[0];
        $height = (int) $image[1];

        if (
            $width < 1
            || $height < 1
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new \RuntimeException(
                'Размерите на снимката не отговарят на изискванията. Максималната резолюция е 50 MP.'
            );
        }

        $exif = @exif_read_data(
            $path,
            'EXIF,GPS',
            true,
            false
        );

        if (!is_array($exif)) {
            throw new \RuntimeException(
                'В снимката липсват необходимите EXIF данни.'
            );
        }

        $gps = $exif['GPS'] ?? [];
        $exifPart = $exif['EXIF'] ?? [];

        $latitude = self::coordinate(
            $gps['GPSLatitude'] ?? null,
            $gps['GPSLatitudeRef'] ?? null,
            'NS'
        );

        $longitude = self::coordinate(
            $gps['GPSLongitude'] ?? null,
            $gps['GPSLongitudeRef'] ?? null,
            'EW'
        );

        $original = (string) (
            $exifPart['DateTimeOriginal'] ?? ''
        );

        $date = \DateTimeImmutable::createFromFormat(
            '!Y:m:d H:i:s',
            $original
        );

        $errors = \DateTimeImmutable::getLastErrors();

        if (
            !$date
            || (
                $errors !== false
                && (
                    $errors['warning_count']
                    || $errors['error_count']
                )
            )
            || $date->format('Y:m:d H:i:s')
                !== $original
        ) {
            throw new \RuntimeException(
                'В снимката липсва валидна дата и час на заснемане.'
            );
        }

        if (
            $date > new \DateTimeImmutable('+10 minutes')
        ) {
            throw new \RuntimeException(
                'Датата и часът на заснемане не могат да бъдат в бъдещето.'
            );
        }

        return [
            'mime_type' =>
                'image/jpeg',

            'file_size' =>
                $size,

            'width' =>
                $width,

            'height' =>
                $height,

            'sha256_hash' =>
                hash_file(
                    'sha256',
                    $path
                ),

            'latitude' =>
                number_format(
                    $latitude,
                    7,
                    '.',
                    ''
                ),

            'longitude' =>
                number_format(
                    $longitude,
                    7,
                    '.',
                    ''
                ),

            'captured_at' =>
                $date->format(
                    'Y-m-d H:i:s'
                ),

            'exif_datetime_original' =>
                $original
        ];
    }

    private static function coordinate(
        mixed $values,
        mixed $reference,
        string $allowed
    ): float {
        $reference = strtoupper(
            trim((string) $reference)
        );

        if (
            !in_array(
                $reference,
                str_split($allowed),
                true
            )
            || !is_array($values)
            || count($values) !== 3
        ) {
            throw new \RuntimeException(
                'В снимката липсват валидни GPS координати.'
            );
        }

        $parts = array_map(
            static fn($v): float =>
                self::rational($v),
            array_values($values)
        );

        $decimal =
            $parts[0]
            + $parts[1] / 60
            + $parts[2] / 3600;

        $limit =
            $allowed === 'NS'
                ? 90
                : 180;

        if (
            !is_finite($decimal)
            || $decimal < 0
            || $decimal > $limit
        ) {
            throw new \RuntimeException(
                'GPS координатите в снимката са извън допустимия диапазон.'
            );
        }

        return in_array(
            $reference,
            ['S', 'W'],
            true
        )
            ? -$decimal
            : $decimal;
    }

    private static function rational(
        mixed $value
    ): float {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (
            !is_string($value)
            || !preg_match(
                '/^(\d+(?:\.\d+)?)\/(\d+(?:\.\d+)?)$/',
                $value,
                $m
            )
            || (float) $m[2] == 0.0
        ) {
            throw new \RuntimeException(
                'GPS стойността в EXIF данните е невалидна.'
            );
        }

        return (float) $m[1]
            / (float) $m[2];
    }
}