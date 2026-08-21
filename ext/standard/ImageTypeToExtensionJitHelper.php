<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * image_type_to_extension() for compiled JIT/AOT modules (#14851, #28314, php-in-PHP).
 *
 * NestedJIT-self-contained (no {@see VmImage} / no static result stash) — peer
 * {@see ImageTypeToMimeTypeJitHelper} / {@see Hex2binJitHelper} #27008. Returns string|false so the
 * bridge can map false → __value__ bool without a tag + static pair (AOT statics / class-const
 * tables were empty under NestedJIT).
 *
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_extension)
 */
final class ImageTypeToExtensionJitHelper
{
    /**
     * @return string|false
     */
    public static function imageTypeToExtensionArgv(int $imageType, bool $includeDot)
    {
        $dotted = self::dottedExtension($imageType);
        if (false === $dotted) {
            return false;
        }
        if ($includeDot) {
            return $dotted;
        }

        // NestedJIT-safe: avoid substr() — drop the leading '.' by offset.
        return self::withoutDot($dotted);
    }

    /** @return string|false */
    private static function dottedExtension(int $imageType)
    {
        switch ($imageType) {
            case 1:
                return '.gif';
            case 2:
                return '.jpeg';
            case 3:
                return '.png';
            case 4:
            case 13:
                return '.swf';
            case 5:
                return '.psd';
            case 6:
            case 15:
                return '.bmp';
            case 7:
            case 8:
                return '.tiff';
            case 9:
                return '.jpc';
            case 10:
                return '.jp2';
            case 11:
                return '.jpx';
            case 12:
                return '.jb2';
            case 14:
                return '.iff';
            case 16:
                return '.xbm';
            case 17:
                return '.ico';
            case 18:
                return '.webp';
            case 19:
                return '.avif';
            case 20:
                return '.heif';
            default:
                return false;
        }
    }

    private static function withoutDot(string $dotted): string
    {
        switch ($dotted) {
            case '.gif':
                return 'gif';
            case '.jpeg':
                return 'jpeg';
            case '.png':
                return 'png';
            case '.swf':
                return 'swf';
            case '.psd':
                return 'psd';
            case '.bmp':
                return 'bmp';
            case '.tiff':
                return 'tiff';
            case '.jpc':
                return 'jpc';
            case '.jp2':
                return 'jp2';
            case '.jpx':
                return 'jpx';
            case '.jb2':
                return 'jb2';
            case '.iff':
                return 'iff';
            case '.xbm':
                return 'xbm';
            case '.ico':
                return 'ico';
            case '.webp':
                return 'webp';
            case '.avif':
                return 'avif';
            case '.heif':
                return 'heif';
            default:
                return '';
        }
    }
}
