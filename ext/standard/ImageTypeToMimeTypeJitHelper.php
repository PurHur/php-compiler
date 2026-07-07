<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * image_type_to_mime_type() for compiled JIT/AOT modules (#17126, php-in-PHP).
 *
 * SSOT for IMAGETYPE_* → MIME mapping (VmImage::imageTypeToMimeType delegates here).
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_mime_type)
 */
final class ImageTypeToMimeTypeJitHelper
{
    public static function lookupArgv(int $imageType): string
    {
        switch ($imageType) {
            case 1:
                return 'image/gif';
            case 2:
                return 'image/jpeg';
            case 3:
                return 'image/png';
            case 4:
            case 13:
                return 'application/x-shockwave-flash';
            case 5:
                return 'image/psd';
            case 6:
                return 'image/bmp';
            case 7:
            case 8:
                return 'image/tiff';
            case 14:
                return 'image/iff';
            case 15:
                return 'image/vnd.wap.wbmp';
            case 9:
                return 'application/octet-stream';
            case 10:
                return 'image/jp2';
            case 16:
                return 'image/xbm';
            case 17:
                return 'image/vnd.microsoft.icon';
            case 18:
                return 'image/webp';
            case 19:
                return 'image/avif';
            case 20:
                return 'image/heif';
            default:
                return 'application/octet-stream';
        }
    }
}
