<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\ext\standard\VmExif;
use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\ext\standard\VmString;

/**
 * EXIF metadata read helpers — php-src ext/exif/exif.c (issue #3400).
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c PHP_FUNCTION(exif_read_data)
 */
final class VmExifRead
{
    private const TIFF_TYPE_BYTE = 1;
    private const TIFF_TYPE_ASCII = 2;
    private const TIFF_TYPE_SHORT = 3;
    private const TIFF_TYPE_LONG = 4;
    private const TIFF_TYPE_RATIONAL = 5;
    private const TIFF_TYPE_UNDEFINED = 7;
    private const TIFF_TYPE_SLONG = 9;
    private const TIFF_TYPE_SRATIONAL = 10;

    /**
     * exif_imagetype() — php-src php_getimagetype() / exif_imagetype().
     *
     * @return int|false IMAGETYPE_* constant
     */
    public static function imageType(string $filename)
    {
        $result = VmImage::getImageSize($filename);
        if (false === $result) {
            return false;
        }

        return (int) $result[2];
    }

    /**
     * exif_thumbnail() — embedded JPEG/TIFF thumbnail bytes (php-src ext/exif/exif.c; #20027).
     *
     * @param-out int|null $width
     * @param-out int|null $height
     * @param-out int|null $imageType
     * @return string|false
     */
    public static function thumbnail(
        string $filename,
        ?int &$width = null,
        ?int &$height = null,
        ?int &$imageType = null
    ): string|false {
        $bytes = \PHPCompiler\ext\standard\VmFs::fileGetContents($filename);
        if (false === $bytes) {
            return false;
        }

        return self::thumbnailFromBytes($bytes, $width, $height, $imageType);
    }

    /**
     * @param-out int|null $width
     * @param-out int|null $height
     * @param-out int|null $imageType
     * @return string|false
     */
    public static function thumbnailFromBytes(
        string $bytes,
        ?int &$width = null,
        ?int &$height = null,
        ?int &$imageType = null
    ): string|false {
        $segment = self::extractJpegExifSegment($bytes);
        if (null === $segment) {
            return false;
        }
        $thumb = self::extractIfd1Thumbnail($segment);
        if (null === $thumb) {
            return false;
        }
        $width = $thumb['width'];
        $height = $thumb['height'];
        $data = $thumb['data'];
        $detected = VmImage::getImageSizeFromBytes($data);
        if (false !== $detected) {
            $imageType = (int) $detected[2];
            if (null === $width) {
                $width = (int) $detected[0];
            }
            if (null === $height) {
                $height = (int) $detected[1];
            }
        } else {
            $imageType = null;
        }

        return $data;
    }

    /**
     * @return array{data: string, width: ?int, height: ?int}|null
     */
    private static function extractIfd1Thumbnail(string $tiff): ?array
    {
        $len = \strlen($tiff);
        if ($len < 8) {
            return null;
        }
        $le = 'II' === \substr($tiff, 0, 2);
        $be = 'MM' === \substr($tiff, 0, 2);
        if (!$le && !$be) {
            return null;
        }
        $version = self::readUint16($tiff, 2, $le);
        if (0x002A !== $version) {
            return null;
        }
        $ifd0Offset = self::readUint32($tiff, 4, $le);
        if ($ifd0Offset >= $len) {
            return null;
        }
        $ifd1Offset = self::readNextIfdOffset($tiff, $ifd0Offset, $le);
        if (null === $ifd1Offset || 0 === $ifd1Offset || $ifd1Offset >= $len) {
            return null;
        }
        $raw = self::readIfdRawTags($tiff, $ifd1Offset, $le);
        // JPEGInterchangeFormat / Length (IFD1 compressed thumbnail)
        if (isset($raw[0x0201], $raw[0x0202])) {
            $off = (int) $raw[0x0201];
            $size = (int) $raw[0x0202];
            if ($off <= 0 || $size <= 0 || $off + $size > $len) {
                return null;
            }
            $data = \substr($tiff, $off, $size);
            $width = isset($raw[0x0100]) ? (int) $raw[0x0100] : null;
            $height = isset($raw[0x0101]) ? (int) $raw[0x0101] : null;

            return ['data' => $data, 'width' => $width, 'height' => $height];
        }

        return null;
    }

    private static function readNextIfdOffset(string $tiff, int $ifdOffset, bool $le): ?int
    {
        $len = \strlen($tiff);
        if ($ifdOffset + 2 > $len) {
            return null;
        }
        $count = self::readUint16($tiff, $ifdOffset, $le);
        $nextOffset = $ifdOffset + 2 + ($count * 12);
        if ($nextOffset + 4 > $len) {
            return null;
        }

        return self::readUint32($tiff, $nextOffset, $le);
    }

    /**
     * Raw IFD tag → integer value map (for thumbnail offset/length tags).
     *
     * @return array<int, int>
     */
    private static function readIfdRawTags(string $tiff, int $offset, bool $le): array
    {
        $len = \strlen($tiff);
        if ($offset + 2 > $len) {
            return [];
        }
        $count = self::readUint16($tiff, $offset, $le);
        $result = [];
        $entryBase = $offset + 2;
        for ($i = 0; $i < $count; ++$i) {
            $entryOffset = $entryBase + ($i * 12);
            if ($entryOffset + 12 > $len) {
                break;
            }
            $tag = self::readUint16($tiff, $entryOffset, $le);
            $type = self::readUint16($tiff, $entryOffset + 2, $le);
            $componentCount = self::readUint32($tiff, $entryOffset + 4, $le);
            $valueOffset = self::readUint32($tiff, $entryOffset + 8, $le);
            if (1 !== $componentCount) {
                continue;
            }
            if (self::TIFF_TYPE_SHORT === $type) {
                $result[$tag] = $valueOffset & 0xffff;
            } elseif (self::TIFF_TYPE_LONG === $type || self::TIFF_TYPE_SLONG === $type) {
                $result[$tag] = $valueOffset;
            }
        }

        return $result;
    }

    /**
     * exif_read_data() — file section + optional IFD0 tags + COMPUTED (#3400, #24582).
     *
     * php-src ext/exif/exif.c returns FILE.* keys and COMPUTED for parseable JPEG/TIFF even
     * when no APP1 EXIF segment is present (SectionsFound empty, Width/Height from getimagesize).
     *
     * @return array<string, array<string, int|string>|int|string>|false
     */
    public static function readData(string $filename)
    {
        $bytes = \PHPCompiler\ext\standard\VmFs::fileGetContents($filename);
        if (false === $bytes) {
            return false;
        }

        return self::readDataFromBytes($bytes, $filename);
    }

    /**
     * @return array<string, array<string, int|string>|int|string>|false
     */
    public static function readDataFromBytes(string $bytes, ?string $filename = null)
    {
        $imageSize = VmImage::getImageSizeFromBytes($bytes);
        if (false === $imageSize) {
            return false;
        }
        $imageType = (int) $imageSize[2];
        if (!self::isExifReadableImageType($imageType)) {
            return false;
        }

        $exifTags = [];
        $sectionsFound = '';
        $segment = self::extractJpegExifSegment($bytes);
        if (null !== $segment) {
            $parsed = self::parseTiffIfd0($segment);
            if (false !== $parsed) {
                $exifTags = $parsed;
                $sectionsFound = 'ANY_TAG, IFD0';
            }
        }

        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        $result = self::buildFileSection($filename, $bytes, $imageType, $sectionsFound);
        $result['COMPUTED'] = self::buildComputedSection($width, $height);
        foreach ($exifTags as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    private static function isExifReadableImageType(int $imageType): bool
    {
        return \in_array($imageType, [
            VmImage::IMAGETYPE_JPEG,
            VmImage::IMAGETYPE_TIFF_II,
            VmImage::IMAGETYPE_TIFF_MM,
        ], true);
    }

    /**
     * FILE section keys — php-src exif_read_from_file() (#24582).
     *
     * @return array<string, int|string>
     */
    private static function buildFileSection(
        ?string $filename,
        string $bytes,
        int $imageType,
        string $sectionsFound
    ): array {
        $fileName = null !== $filename ? VmString::basename($filename) : '';
        $fileDateTime = 0;
        $fileSize = \strlen($bytes);
        if (null !== $filename) {
            $mtime = \PHPCompiler\ext\standard\VmFs::fileMtime($filename);
            if (false !== $mtime) {
                $fileDateTime = $mtime;
            }
            $size = \PHPCompiler\ext\standard\VmFs::fileSize($filename);
            if (false !== $size) {
                $fileSize = $size;
            }
        }

        return [
            'FileName' => $fileName,
            'FileDateTime' => $fileDateTime,
            'FileSize' => $fileSize,
            'FileType' => $imageType,
            'MimeType' => VmImage::imageTypeToMimeType($imageType),
            'SectionsFound' => $sectionsFound,
        ];
    }

    /**
     * COMPUTED section — php-src exif build COMputed array via getimagesize dimensions.
     *
     * @return array<string, int|string>
     */
    private static function buildComputedSection(int $width, int $height): array
    {
        return [
            'html' => \sprintf('width="%d" height="%d"', $width, $height),
            'Height' => $height,
            'Width' => $width,
            'IsColor' => 1,
        ];
    }

    private static function extractJpegExifSegment(string $data): ?string
    {
        $len = \strlen($data);
        if ($len < 4 || 0 !== \strncmp($data, "\xFF\xD8", 2)) {
            return null;
        }
        $offset = 2;
        while ($offset + 4 <= $len) {
            if ("\xFF" !== $data[$offset]) {
                return null;
            }
            $marker = \ord($data[$offset + 1]);
            if (0xE1 !== $marker) {
                if ($marker >= 0xD0 && $marker <= 0xD9) {
                    $offset += 2;
                    continue;
                }
                if ($offset + 4 > $len) {
                    return null;
                }
                $segLen = (\ord($data[$offset + 2]) << 8) + \ord($data[$offset + 3]);
                $offset += 2 + $segLen;
                continue;
            }
            if ($offset + 4 > $len) {
                return null;
            }
            $segLen = (\ord($data[$offset + 2]) << 8) + \ord($data[$offset + 3]);
            if ($offset + 2 + $segLen > $len) {
                return null;
            }
            $payload = \substr($data, $offset + 4, $segLen - 2);
            if (0 === \strncmp($payload, "Exif\x00\x00", 6)) {
                return \substr($payload, 6);
            }
            $offset += 2 + $segLen;
        }

        return null;
    }

    /**
     * @return array<string, int|string>|false
     */
    private static function parseTiffIfd0(string $tiff): array|false
    {
        $len = \strlen($tiff);
        if ($len < 8) {
            return false;
        }
        $le = 'II' === \substr($tiff, 0, 2);
        $be = 'MM' === \substr($tiff, 0, 2);
        if (!$le && !$be) {
            return false;
        }
        $version = self::readUint16($tiff, 2, $le);
        if (0x002A !== $version) {
            return false;
        }
        $ifdOffset = self::readUint32($tiff, 4, $le);
        if ($ifdOffset >= $len) {
            return false;
        }

        return self::readIfd($tiff, $ifdOffset, $le);
    }

    /**
     * @return array<string, int|string>
     */
    private static function readIfd(string $tiff, int $offset, bool $le): array
    {
        $len = \strlen($tiff);
        if ($offset + 2 > $len) {
            return [];
        }
        $count = self::readUint16($tiff, $offset, $le);
        $result = [];
        $entryBase = $offset + 2;
        for ($i = 0; $i < $count; ++$i) {
            $entryOffset = $entryBase + ($i * 12);
            if ($entryOffset + 12 > $len) {
                break;
            }
            $tag = self::readUint16($tiff, $entryOffset, $le);
            $type = self::readUint16($tiff, $entryOffset + 2, $le);
            $componentCount = self::readUint32($tiff, $entryOffset + 4, $le);
            $valueOffset = self::readUint32($tiff, $entryOffset + 8, $le);
            $name = VmExif::tagName($tag);
            if (false === $name) {
                continue;
            }
            $value = self::readTagValue($tiff, $type, $componentCount, $valueOffset, $le);
            if (null === $value) {
                continue;
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @return int|string|null
     */
    private static function readTagValue(
        string $tiff,
        int $type,
        int $count,
        int $valueOffset,
        bool $le
    ): int|string|null {
        $typeSize = self::tiffTypeSize($type);
        if (null === $typeSize) {
            return null;
        }
        $totalSize = $typeSize * $count;
        $len = \strlen($tiff);
        $dataOffset = $valueOffset;
        if ($totalSize > 4) {
            if ($dataOffset + $totalSize > $len) {
                return null;
            }
        } else {
            $packed = \pack($le ? 'V' : 'N', $valueOffset);
            $tiff = $packed.$tiff;
            $dataOffset = 0;
            $len = \strlen($tiff);
        }

        switch ($type) {
            case self::TIFF_TYPE_BYTE:
            case self::TIFF_TYPE_UNDEFINED:
                if (1 === $count) {
                    return \ord($tiff[$dataOffset]);
                }
                $bytes = \substr($tiff, $dataOffset, $count);

                return \bin2hex($bytes);

            case self::TIFF_TYPE_ASCII:
                $raw = \substr($tiff, $dataOffset, $count);
                $nul = \strpos($raw, "\x00");
                if (false !== $nul) {
                    $raw = \substr($raw, 0, $nul);
                }

                return $raw;

            case self::TIFF_TYPE_SHORT:
                if (1 === $count) {
                    return self::readUint16($tiff, $dataOffset, $le);
                }

                return self::readUint16Array($tiff, $dataOffset, $count, $le);

            case self::TIFF_TYPE_LONG:
            case self::TIFF_TYPE_SLONG:
                if (1 === $count) {
                    return self::readUint32($tiff, $dataOffset, $le);
                }

                return self::readUint32Array($tiff, $dataOffset, $count, $le);

            case self::TIFF_TYPE_RATIONAL:
            case self::TIFF_TYPE_SRATIONAL:
                if (1 !== $count) {
                    return null;
                }
                $num = self::readUint32($tiff, $dataOffset, $le);
                $den = self::readUint32($tiff, $dataOffset + 4, $le);
                if (0 === $den) {
                    return '0/0';
                }

                return $num.'/'.$den;

            default:
                return null;
        }
    }

    private static function tiffTypeSize(int $type): ?int
    {
        return match ($type) {
            self::TIFF_TYPE_BYTE, self::TIFF_TYPE_ASCII, self::TIFF_TYPE_UNDEFINED => 1,
            self::TIFF_TYPE_SHORT => 2,
            self::TIFF_TYPE_LONG, self::TIFF_TYPE_SLONG => 4,
            self::TIFF_TYPE_RATIONAL, self::TIFF_TYPE_SRATIONAL => 8,
            default => null,
        };
    }

    private static function readUint16(string $data, int $offset, bool $le): int
    {
        $chunk = \substr($data, $offset, 2);
        $unpacked = \unpack($le ? 'v' : 'n', $chunk);

        return (int) $unpacked[1];
    }

    private static function readUint32(string $data, int $offset, bool $le): int
    {
        $chunk = \substr($data, $offset, 4);
        $unpacked = \unpack($le ? 'V' : 'N', $chunk);

        return (int) $unpacked[1];
    }

    /** @return string */
    private static function readUint16Array(string $data, int $offset, int $count, bool $le): string
    {
        $parts = [];
        for ($i = 0; $i < $count; ++$i) {
            $parts[] = (string) self::readUint16($data, $offset + ($i * 2), $le);
        }

        return \implode(',', $parts);
    }

    /** @return string */
    private static function readUint32Array(string $data, int $offset, int $count, bool $le): string
    {
        $parts = [];
        for ($i = 0; $i < $count; ++$i) {
            $parts[] = (string) self::readUint32($data, $offset + ($i * 4), $le);
        }

        return \implode(',', $parts);
    }
}
