<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * IMAGETYPE_* helpers — php-src ext/standard/image.c (issues #6091, #6063, #3271, #22787).
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
    /** Internal type id; userland constant only when {@see CompilerVersion::supportsImagTypeHeif()} (#22787). */
    public const IMAGETYPE_HEIF = 20;
    /** php-src IMAGE_FILETYPE_COUNT with HEIF (PHP 8.5+). Pre-HEIF count is {@see IMAGETYPE_COUNT_PRE_HEIF}. */
    public const IMAGETYPE_COUNT = 21;
    /** IMAGE_FILETYPE_COUNT before IMAGETYPE_HEIF (AVIF is last → count 20). */
    public const IMAGETYPE_COUNT_PRE_HEIF = 20;

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
        $out = [
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
        ];
        // IMAGETYPE_HEIF is PHP 8.5+ (php-src image.c / UPGRADING); keep internal sniffing without defined() (#22787).
        if (CompilerVersion::supportsImagTypeHeif()) {
            $out['IMAGETYPE_HEIF'] = self::IMAGETYPE_HEIF;
            $out['IMAGETYPE_COUNT'] = self::IMAGETYPE_COUNT;
        } else {
            $out['IMAGETYPE_COUNT'] = self::IMAGETYPE_COUNT_PRE_HEIF;
        }

        return $out;
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
        return ImageTypeToMimeTypeJitHelper::mimeArgv($imageType);
    }

    /**
     * getimagesize() from path — php-src php_getimagesize() / php_getimagesize_from_any() (#3271).
     *
     * @param array<string, mixed>|null $imageinfo
     *
     * @return array<int|string, int|string>|false
     */
    public static function getImageSize(string $filename, ?array &$imageinfo = null)
    {
        $data = VmFs::fileGetContents($filename);
        if (false === $data) {
            return false;
        }

        return self::getImageSizeFromBytes($data, $imageinfo);
    }

    /**
     * getimagesizefromstring() — php-src PHP_FUNCTION(getimagesizefromstring) (#3271).
     *
     * @param array<string, mixed>|null $imageinfo
     *
     * @return array<int|string, int|string>|false
     */
    public static function getImageSizeFromBytes(string $data, ?array &$imageinfo = null)
    {
        $parsed = self::parseImageSizeFromBytes($data);
        if (false === $parsed) {
            return false;
        }
        if (null !== $imageinfo) {
            $imageinfo = self::extractImageInfo($data, (int) $parsed[2]);
        }

        return $parsed;
    }

    /**
     * php-src php_getimagesize_from_any() — E_NOTICE for data:/php:// wrappers, not regular files (#16434, #18405).
     */
    public static function shouldEmitImageReadNoticeForPath(string $filename): bool
    {
        return VmDataUri::isDataUri($filename)
            || VmFsPhpWrapper::isPhpWrapperPath($filename);
    }

    /**
     * php-src php_getimagetype() — E_NOTICE only when fewer than 12 bytes were read (#12930, #18572).
     *
     * After signature probes, {@see php_getimagetype} emits "Error reading from …!" only when
     * `twelve_bytes_read` is false (ext/standard/image.c). Longer payloads that fail all format
     * checks return IMAGE_FILETYPE_UNKNOWN silently — unlike getimagesize() on regular files
     * where only data:/php:// paths gate notices (#16434).
     */
    public static function shouldEmitImageReadNoticeForBytes(string $data): bool
    {
        return \strlen($data) < 12;
    }

    /**
     * php-src ext/standard/image.c php_getimagesize_from_any() — E_NOTICE on read/parse failure.
     */
    public static function emitImageReadNotice(Frame $frame, string $function, string $source): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf('%s(): Error reading from %s!', $function, $source),
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * True when payload bytes were readable but image header parse failed (vs stream open failure).
     */
    public static function pathPayloadReadable(string $filename): bool
    {
        if (VmDataUri::isDataUri($filename)) {
            return false !== VmDataUri::decode($filename);
        }
        if (VmFsPhpWrapper::isPhpWrapperPath($filename)) {
            return false !== VmFs::fileGetContents($filename);
        }

        return is_file($filename) && is_readable($filename);
    }

    /** @param array<string, string> $imageinfo */
    public static function writeImageInfoVariable(\PHPCompiler\VM\Variable $target, array $imageinfo): void
    {
        $target = $target->resolveIndirect();
        $ht = new HashTable();
        foreach ($imageinfo as $key => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add($key, $slot);
        }
        $target->array($ht);
    }

    /**
     * @param array<int|string, int|string> $result
     */
    public static function imageSizeResultToHashTable(array $result): HashTable
    {
        $ht = new HashTable();
        foreach ([0, 1, 2, 3] as $index) {
            if (!\array_key_exists($index, $result)) {
                continue;
            }
            $slot = new Variable();
            $value = $result[$index];
            if (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string((string) $value);
            }
            $ht->updateIndex($index, $slot);
        }
        foreach (['bits', 'channels', 'mime'] as $key) {
            if (!\array_key_exists($key, $result)) {
                continue;
            }
            $slot = new Variable();
            $value = $result[$key];
            if (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string((string) $value);
            }
            $ht->add($key, $slot);
        }

        return $ht;
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseImageSizeFromBytes(string $data)
    {
        $len = \strlen($data);
        if ($len < 10) {
            return false;
        }

        if ($len >= 24 && 0 === \strncmp($data, "\x89PNG\r\n\x1a\n", 8)) {
            return self::parsePng($data);
        }
        if ($len >= 10 && (0 === \strncmp($data, 'GIF87a', 6) || 0 === \strncmp($data, 'GIF89a', 6))) {
            return self::parseGif($data);
        }
        if ($len >= 2 && 0 === \strncmp($data, "\xff\xd8\xff", 3)) {
            return self::parseJpeg($data);
        }
        if ($len >= 26 && 'BM' === \substr($data, 0, 2)) {
            return self::parseBmp($data);
        }
        if ($len >= 30 && 'RIFF' === \substr($data, 0, 4) && 'WEBP' === \substr($data, 8, 4)) {
            return self::parseWebp($data);
        }
        if ($len >= 12 && 'ftyp' === \substr($data, 4, 4)) {
            return self::parseAvif($data);
        }

        return false;
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parsePng(string $data)
    {
        if (\strlen($data) < 24 || 'IHDR' !== \substr($data, 12, 4)) {
            return false;
        }
        $width = self::readUint32Be($data, 16);
        $height = self::readUint32Be($data, 20);
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $bits = \ord($data[24]);
        $colorType = \ord($data[25]);

        return self::buildImageSizeResult($width, $height, self::IMAGETYPE_PNG, $bits, self::pngColorTypeToChannels($colorType));
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseGif(string $data)
    {
        if (\strlen($data) < 11) {
            return false;
        }
        $width = self::readUint16Le($data, 6);
        $height = self::readUint16Le($data, 8);
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $packed = \ord($data[10]);
        $bits = (($packed >> 4) & 0x07) + 1;
        $channels = (0 !== ($packed & 0x80)) ? 3 : null;

        return self::buildImageSizeResult($width, $height, self::IMAGETYPE_GIF, $bits, $channels);
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseJpeg(string $data)
    {
        $len = \strlen($data);
        $pos = 2;
        while ($pos + 1 < $len) {
            if (0xFF !== \ord($data[$pos])) {
                ++$pos;

                continue;
            }
            $marker = \ord($data[$pos + 1]);
            if (0xD9 === $marker) {
                break;
            }
            // RSTn markers (0xD0–0xD7) have no length field — php-src ext/standard/image.c php_parsejpeg.
            if ($marker >= 0xD0 && $marker <= 0xD7) {
                $pos += 2;

                continue;
            }
            if ($pos + 3 >= $len) {
                return false;
            }
            $segmentLen = self::readUint16Be($data, $pos + 2);
            if ($segmentLen < 2) {
                return false;
            }
            if ($marker >= 0xC0 && $marker <= 0xCF && !\in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                if ($pos + 9 >= $len) {
                    return false;
                }
                $bits = \ord($data[$pos + 4]);
                $height = self::readUint16Be($data, $pos + 5);
                $width = self::readUint16Be($data, $pos + 7);
                $channels = \ord($data[$pos + 9]);
                if ($width <= 0 || $height <= 0) {
                    return false;
                }

                return self::buildImageSizeResult($width, $height, self::IMAGETYPE_JPEG, $bits, $channels);
            }
            $pos += 2 + $segmentLen;
        }

        return false;
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseBmp(string $data)
    {
        if (\strlen($data) < 26) {
            return false;
        }
        $width = self::readUint32Le($data, 18);
        $height = self::readUint32Le($data, 22);
        $bits = \ord($data[28]);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        return self::buildImageSizeResult($width, $height, self::IMAGETYPE_BMP, $bits, null);
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseWebp(string $data)
    {
        if (\strlen($data) < 30) {
            return false;
        }
        if ('VP8 ' === \substr($data, 12, 4)) {
            if (\strlen($data) < 30) {
                return false;
            }
            $width = self::readUint16Le($data, 26) & 0x3FFF;
            $height = self::readUint16Le($data, 28) & 0x3FFF;
            if ($width <= 0 || $height <= 0) {
                return false;
            }

            return self::buildImageSizeResult($width, $height, self::IMAGETYPE_WEBP, 8, 3);
        }
        if ('VP8L' === \substr($data, 12, 4) && \strlen($data) >= 25) {
            $bitsField = self::readUint32Le($data, 21);
            $width = ($bitsField & 0x3FFF) + 1;
            $height = (($bitsField >> 14) & 0x3FFF) + 1;
            if ($width <= 0 || $height <= 0) {
                return false;
            }

            return self::buildImageSizeResult($width, $height, self::IMAGETYPE_WEBP, 8, 4);
        }

        return false;
    }

    /**
     * @return array<int|string, int|string>|false
     */
    private static function parseAvif(string $data)
    {
        $dims = \PHPCompiler\ext\gd\VmGdAvif::readDimensions($data);
        if (false === $dims) {
            return false;
        }

        return self::buildImageSizeResult($dims[0], $dims[1], self::IMAGETYPE_AVIF, 8, 3);
    }

    /**
     * @return array<int|string, int|string>
     */
    private static function buildImageSizeResult(int $width, int $height, int $type, int $bits, ?int $channels): array
    {
        $result = [
            0 => $width,
            1 => $height,
            2 => $type,
            3 => \sprintf('width="%d" height="%d"', $width, $height),
            'bits' => $bits,
            'mime' => self::imageTypeToMimeType($type),
        ];
        if (null !== $channels && $channels > 0 && self::shouldIncludeChannels($type, $channels)) {
            $result['channels'] = $channels;
        }

        return $result;
    }

    private static function shouldIncludeChannels(int $type, int $channels): bool
    {
        if (\in_array($type, [self::IMAGETYPE_JPEG, self::IMAGETYPE_GIF], true)) {
            return true;
        }

        return false;
    }

    private static function pngColorTypeToChannels(int $colorType): ?int
    {
        return match ($colorType) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            6 => 4,
            default => null,
        };
    }

    /** @return array<string, string> */
    private static function extractImageInfo(string $data, int $type): array
    {
        if (self::IMAGETYPE_PNG !== $type || \strlen($data) < 8) {
            return [];
        }
        $info = [];
        $pos = 8;
        $len = \strlen($data);
        while ($pos + 8 <= $len) {
            $chunkLen = self::readUint32Be($data, $pos);
            $chunkType = \substr($data, $pos + 4, 4);
            if ($chunkLen < 0 || $pos + 8 + $chunkLen > $len) {
                break;
            }
            if ('IEND' === $chunkType) {
                break;
            }
            if (str_starts_with($chunkType, 'APP')) {
                $info[$chunkType] = \substr($data, $pos + 8, $chunkLen);
            }
            $pos += 8 + $chunkLen + 4;
        }

        return $info;
    }

    private static function readUint16Be(string $data, int $offset): int
    {
        $chunk = \substr($data, $offset, 2);
        if (2 !== \strlen($chunk)) {
            return 0;
        }
        $unpacked = \unpack('n', $chunk);

        return (int) ($unpacked[1] ?? 0);
    }

    private static function readUint16Le(string $data, int $offset): int
    {
        $chunk = \substr($data, $offset, 2);
        if (2 !== \strlen($chunk)) {
            return 0;
        }
        $unpacked = \unpack('v', $chunk);

        return (int) ($unpacked[1] ?? 0);
    }

    private static function readUint32Be(string $data, int $offset): int
    {
        $chunk = \substr($data, $offset, 4);
        if (4 !== \strlen($chunk)) {
            return 0;
        }
        $unpacked = \unpack('N', $chunk);

        return (int) ($unpacked[1] ?? 0);
    }

    private static function readUint32Le(string $data, int $offset): int
    {
        $chunk = \substr($data, $offset, 4);
        if (4 !== \strlen($chunk)) {
            return 0;
        }
        $unpacked = \unpack('V', $chunk);

        return (int) ($unpacked[1] ?? 0);
    }
}
