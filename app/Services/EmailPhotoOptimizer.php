<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EmailPhotoOptimizer
{
    /**
     * Maximum dimensions for the email copy.
     */
    public const INITIAL_MAX_WIDTH = 1200;
    public const INITIAL_MAX_HEIGHT = 1200;

    /**
     * Maximum allowed JPEG size.
     *
     * Keep a significant margin below typical mailbox limits.
     */
    public const MAX_BYTES = 300 * 1024;

    /**
     * JPEG quality range.
     */
    public const INITIAL_QUALITY = 75;
    public const MIN_QUALITY = 40;

    /**
     * Minimum acceptable email image dimensions.
     */
    public const MIN_WIDTH = 640;
    public const MIN_HEIGHT = 360;

    /**
     * Create a small JPEG copy for email delivery.
     *
     * The original image is NEVER modified.
     *
     * The optimizer tries to stay below MAX_BYTES by:
     *
     * 1. resizing the image;
     * 2. reducing JPEG quality;
     * 3. reducing dimensions if necessary.
     *
     * @return array{
     *     path:string,
     *     filename:string,
     *     mime:string,
     *     size:int,
     *     width:int,
     *     height:int
     * }
     */
    public static function optimize(
        string $sourcePath,
        string $originalName
    ): array {
        if (
            !is_file($sourcePath) ||
            !is_readable($sourcePath)
        ) {
            throw new RuntimeException(
                'Оригиналната снимка не може да бъде прочетена.'
            );
        }

        $imageInfo = @getimagesize(
            $sourcePath
        );

        if (
            !is_array($imageInfo) ||
            ($imageInfo[2] ?? null) !== IMAGETYPE_JPEG
        ) {
            throw new RuntimeException(
                'Само JPEG снимки могат да бъдат подготвени за изпращане.'
            );
        }

        $source = @imagecreatefromjpeg(
            $sourcePath
        );

        if ($source === false) {
            throw new RuntimeException(
                'JPEG снимката не може да бъде заредена.'
            );
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            if (
                $sourceWidth < 1 ||
                $sourceHeight < 1
            ) {
                throw new RuntimeException(
                    'Размерите на оригиналната снимка са невалидни.'
                );
            }

            /*
             * Create private temporary directory.
             */
            $tmpDir =
                APP_ROOT .
                '/storage/uploads/tmp/email';

            if (
                !is_dir($tmpDir) &&
                !mkdir($tmpDir, 0750, true) &&
                !is_dir($tmpDir)
            ) {
                throw new RuntimeException(
                    'Временната директория за email снимки не може да бъде създадена.'
                );
            }

            /*
             * Base dimensions.
             *
             * We deliberately start much smaller than the old
             * 2560 × 2560 version because the receiving mailbox
             * has demonstrated a practical attachment limit.
             */
            $maxWidth = self::INITIAL_MAX_WIDTH;
            $maxHeight = self::INITIAL_MAX_HEIGHT;

            while (true) {

                /*
                 * Calculate dimensions while preserving aspect ratio.
                 */
                $scale = min(
                    1.0,
                    $maxWidth / $sourceWidth,
                    $maxHeight / $sourceHeight
                );

                $targetWidth = max(
                    1,
                    (int)round(
                        $sourceWidth * $scale
                    )
                );

                $targetHeight = max(
                    1,
                    (int)round(
                        $sourceHeight * $scale
                    )
                );

                /*
                 * Create RGB canvas.
                 */
                $target = imagecreatetruecolor(
                    $targetWidth,
                    $targetHeight
                );

                if ($target === false) {
                    throw new RuntimeException(
                        'Не може да бъде създадено оптимизирано изображение.'
                    );
                }

                try {
                    /*
                     * JPEG does not support transparency.
                     * Fill explicitly to avoid uninitialized data.
                     */
                    $white = imagecolorallocate(
                        $target,
                        255,
                        255,
                        255
                    );

                    imagefill(
                        $target,
                        0,
                        0,
                        $white
                    );

                    /*
                     * High-quality resampling.
                     */
                    if (
                        !imagecopyresampled(
                            $target,
                            $source,
                            0,
                            0,
                            0,
                            0,
                            $targetWidth,
                            $targetHeight,
                            $sourceWidth,
                            $sourceHeight
                        )
                    ) {
                        throw new RuntimeException(
                            'Оптимизирането на снимката не бе успешно.'
                        );
                    }

                    /*
                     * Try progressively lower JPEG qualities.
                     */
                    $quality = self::INITIAL_QUALITY;

                    while (
                        $quality >= self::MIN_QUALITY
                    ) {
                        $temporaryPath =
                            $tmpDir .
                            '/' .
                            bin2hex(
                                random_bytes(24)
                            ) .
                            '.jpg';

                        try {
                            if (
                                !imagejpeg(
                                    $target,
                                    $temporaryPath,
                                    $quality
                                )
                            ) {
                                throw new RuntimeException(
                                    'Оптимизираната снимка не може да бъде записана.'
                                );
                            }

                            clearstatcache(
                                true,
                                $temporaryPath
                            );

                            $size = filesize(
                                $temporaryPath
                            );

                            if (
                                $size !== false &&
                                $size <= self::MAX_BYTES
                            ) {
                                $safeOriginalName =
                                    pathinfo(
                                        $originalName,
                                        PATHINFO_FILENAME
                                    );

                                $safeOriginalName =
                                    preg_replace(
                                        '/[^A-Za-z0-9._-]+/',
                                        '_',
                                        $safeOriginalName
                                    ) ?: 'signal';

                                return [
                                    'path' =>
                                        $temporaryPath,

                                    'filename' =>
                                        $safeOriginalName .
                                        '_email.jpg',

                                    'mime' =>
                                        'image/jpeg',

                                    'size' =>
                                        (int)$size,

                                    'width' =>
                                        $targetWidth,

                                    'height' =>
                                        $targetHeight,
                                ];
                            }

                            /*
                             * File was too large.
                             */
                            @unlink(
                                $temporaryPath
                            );

                        } catch (\Throwable $e) {
                            if (
                                is_file(
                                    $temporaryPath
                                )
                            ) {
                                @unlink(
                                    $temporaryPath
                                );
                            }

                            throw $e;
                        }

                        /*
                         * Lower quality gradually.
                         */
                        $quality -=
                            $quality > 60
                                ? 5
                                : 3;
                    }

                } finally {
                    imagedestroy(
                        $target
                    );
                }

                /*
                 * We reached MIN_QUALITY and the image is still
                 * too large.
                 *
                 * Reduce dimensions and try again.
                 */
                $nextWidth =
                    (int)floor(
                        $maxWidth * 0.80
                    );

                $nextHeight =
                    (int)floor(
                        $maxHeight * 0.80
                    );

                /*
                 * Do not continue indefinitely.
                 */
                if (
                    $nextWidth < self::MIN_WIDTH ||
                    $nextHeight < self::MIN_HEIGHT
                ) {
                    break;
                }

                $maxWidth = $nextWidth;
                $maxHeight = $nextHeight;
            }

            throw new RuntimeException(
                'Снимката не може да бъде оптимизирана до допустимия размер за email.'
            );

        } finally {
            imagedestroy(
                $source
            );
        }
    }
}