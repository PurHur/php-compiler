<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * IMAGETYPE_* helpers — php-src ext/standard/image.c (issues #6091, #6063).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_image.h image_filetype enum
 */
final class VmImage
{
    public const IMAGETYPE_UNKNOWN = 0;
    public const IMAGETYPE_GIF = 1;
    public const IMAGETYPE_JPEG = 2;
    public const IMAGETYPE_PNG = 3;
    public const IMAGETYPE_SWF = 4;
    public const IMAGETYPE_PSD = 5;
    public const IMAGETYPE_BMP = 6;
    public const IMAGETYPE_TIFF_II = 7;
    public const IMAGETYPE_TIFF_MM = 8;
    public const IMAGETYPE_JPC = 9;
    public const IMAGETYPE_JP2 = 10;
    public const IMAGETYPE_JPX = 11;
    public const IMAGETYPE_JB2 = 12;
    public const IMAGETYPE_SWC = 13;
    public const IMAGETYPE_IFF = 14;
    public const IMAGETYPE_WBMP = 15;
    public const IMAGETYPE_JPEG2000 = 9;
    public const IMAGETYPE_XBM = 16;
    public const IMAGETYPE_ICO = 17;
    public const IMAGETYPE_WEBP = 18;
    public const IMAGETYPE_AVIF = 19;
    public const IMAGETYPE_HEIF = 20;
    public const IMAGETYPE_COUNT = 21;

    /** @var array<int, string> MIME type per IMAGE_FILETYPE_* (php_image_type_to_mime_type) */
    private const MIME_TYPES = [
        self::IMAGETYPE_GIF => 'image/gif',
        self::IMAGETYPE_JPEG => 'image/jpeg',
        self::IMAGETYPE_PNG => 'image/png',
        self::IMAGETYPE_SWF => 'application/x-shockwave-flash',
        self::IMAGETYPE_SWC => 'application/x-shockwave-flash',
        self::IMAGETYPE_PSD => 'image/psd',
        self::IMAGETYPE_BMP => 'image/bmp',
        self::IMAGETYPE_TIFF_II => 'image/tiff',
        self::IMAGETYPE_TIFF_MM => 'image/tiff',
        self::IMAGETYPE_IFF => 'image/iff',
        self::IMAGETYPE_WBMP => 'image/vnd.wap.wbmp',
        self::IMAGETYPE_JPC => 'application/octet-stream',
        self::IMAGETYPE_JP2 => 'image/jp2',
        self::IMAGETYPE_XBM => 'image/xbm',
        self::IMAGETYPE_ICO => 'image/vnd.microsoft.icon',
        self::IMAGETYPE_WEBP => 'image/webp',
        self::IMAGETYPE_AVIF => 'image/avif',
        self::IMAGETYPE_HEIF => 'image/heif',
    ];

    private const MIME_TYPE_UNKNOWN = 'application/octet-stream';

    /** @var array<int, string> dotted extension per IMAGE_FILETYPE_* */
    private const EXTENSIONS = [
        self::IMAGETYPE_GIF => '.gif',
        self::IMAGETYPE_JPEG => '.jpeg',
        self::IMAGETYPE_PNG => '.png',
        self::IMAGETYPE_SWF => '.swf',
        self::IMAGETYPE_SWC => '.swf',
        self::IMAGETYPE_PSD => '.psd',
        self::IMAGETYPE_BMP => '.bmp',
        self::IMAGETYPE_WBMP => '.bmp',
        self::IMAGETYPE_TIFF_II => '.tiff',
        self::IMAGETYPE_TIFF_MM => '.tiff',
        self::IMAGETYPE_IFF => '.iff',
        self::IMAGETYPE_JPC => '.jpc',
        self::IMAGETYPE_JP2 => '.jp2',
        self::IMAGETYPE_JPX => '.jpx',
        self::IMAGETYPE_JB2 => '.jb2',
        self::IMAGETYPE_XBM => '.xbm',
        self::IMAGETYPE_ICO => '.ico',
        self::IMAGETYPE_WEBP => '.webp',
        self::IMAGETYPE_AVIF => '.avif',
        self::IMAGETYPE_HEIF => '.heif',
    ];

    /** @return array<string, int> */
    public static function constants(): array
    {
        return [
            'IMAGETYPE_UNKNOWN' => self::IMAGETYPE_UNKNOWN,
            'IMAGETYPE_GIF' => self::IMAGETYPE_GIF,
            'IMAGETYPE_JPEG' => self::IMAGETYPE_JPEG,
            'IMAGETYPE_PNG' => self::IMAGETYPE_PNG,
            'IMAGETYPE_SWF' => self::IMAGETYPE_SWF,
            'IMAGETYPE_PSD' => self::IMAGETYPE_PSD,
            'IMAGETYPE_BMP' => self::IMAGETYPE_BMP,
            'IMAGETYPE_TIFF_II' => self::IMAGETYPE_TIFF_II,
            'IMAGETYPE_TIFF_MM' => self::IMAGETYPE_TIFF_MM,
            'IMAGETYPE_JPC' => self::IMAGETYPE_JPC,
            'IMAGETYPE_JP2' => self::IMAGETYPE_JP2,
            'IMAGETYPE_JPX' => self::IMAGETYPE_JPX,
            'IMAGETYPE_JB2' => self::IMAGETYPE_JB2,
            'IMAGETYPE_SWC' => self::IMAGETYPE_SWC,
            'IMAGETYPE_IFF' => self::IMAGETYPE_IFF,
            'IMAGETYPE_WBMP' => self::IMAGETYPE_WBMP,
            'IMAGETYPE_JPEG2000' => self::IMAGETYPE_JPEG2000,
            'IMAGETYPE_XBM' => self::IMAGETYPE_XBM,
            'IMAGETYPE_ICO' => self::IMAGETYPE_ICO,
            'IMAGETYPE_WEBP' => self::IMAGETYPE_WEBP,
            'IMAGETYPE_AVIF' => self::IMAGETYPE_AVIF,
            'IMAGETYPE_HEIF' => self::IMAGETYPE_HEIF,
            'IMAGETYPE_COUNT' => self::IMAGETYPE_COUNT,
        ];
    }

    /**
     * @return string|false
     */
    public static function imageTypeToExtension(int $imageType, bool $includeDot = true)
    {
        $dotted = self::EXTENSIONS[$imageType] ?? false;
        if (false === $dotted) {
            return false;
        }
        if ($includeDot) {
            return $dotted;
        }

        return substr($dotted, 1);
    }

    /**
     * php-src ext/standard/image.c php_image_type_to_mime_type (#6063).
     */
    public static function imageTypeToMimeType(int $imageType): string
    {
        return self::MIME_TYPES[$imageType] ?? self::MIME_TYPE_UNKNOWN;
    }
}
