<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * zstd_compress()/zstd_decompress() for VM, JIT, and AOT (#8869, php-in-PHP).
 *
 * Codec lives here so nested JIT/AOT compiles the full implementation (no ExternalMethod stubs).
 * php-src: ext/zstd/zstd.c
 */
final class ZstdJitHelper
{
    private const MAGIC = "\x28\xb5\x2f\xfd";

    private const DEFAULT_LEVEL = 3;

    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 22;

    private const BLOCK_TYPE_RAW = 0;

    private const BLOCK_TYPE_RLE = 1;

    public static function compress(string $data, int $level = self::DEFAULT_LEVEL): ?string
    {
        if ($level < self::MIN_LEVEL || $level > self::MAX_LEVEL) {
            return null;
        }

        $len = \strlen($data);
        $header = self::MAGIC;
        $header = $header.self::frameHeaderDescriptor($len);
        $header = $header.self::frameContentSizeField($len);
        $header = $header.self::encodeBlock($data, true);

        return $header;
    }

    public static function decompress(string $data): ?string
    {
        $len = \strlen($data);
        if (0 === $len) {
            return '';
        }
        if ($len < 4 || \substr($data, 0, 4) !== self::MAGIC) {
            return null;
        }

        $offset = 4;
        $descriptor = self::byteAt($data, $offset);
        $offset = $offset + 1;

        $fcsFlag = $descriptor & 0x03;
        $singleSegment = 0 !== ($descriptor & 0x04);
        $contentChecksum = 0 !== ($descriptor & 0x40);
        $dictionaryId = 0 !== ($descriptor & 0x80);

        if (!$singleSegment) {
            if ($offset >= $len) {
                return null;
            }
            $offset = $offset + 1;
        }

        if ($dictionaryId) {
            if ($offset >= $len) {
                return null;
            }
            $dictSize = self::dictionaryIdSize(self::byteAt($data, $offset));
            $offset = $offset + $dictSize;
            if ($offset > $len) {
                return null;
            }
        }

        $declaredSize = -1;
        if ($fcsFlag > 0) {
            $sizeBytes = self::variableFieldSize($fcsFlag);
            if ($sizeBytes <= 0 || $offset + $sizeBytes > $len) {
                return null;
            }
            $declaredSize = self::readLittleEndianUInt($data, $offset, $sizeBytes);
            $offset = $offset + $sizeBytes;
        }

        $out = '';
        $total = 0;
        while ($offset + 3 <= $len) {
            $blockHeader = self::readLittleEndianUInt($data, $offset, 3);
            $offset = $offset + 3;
            $lastBlock = 0 !== ($blockHeader & 0x01);
            $blockType = ($blockHeader >> 1) & 0x03;
            $blockSize = $blockHeader >> 3;
            if ($offset + $blockSize > $len) {
                return null;
            }
            $chunk = null;
            if (self::BLOCK_TYPE_RAW === $blockType) {
                $chunk = self::decodeRawBlock($data, $offset, $blockSize);
            } elseif (self::BLOCK_TYPE_RLE === $blockType) {
                $chunk = self::decodeRleBlock($data, $offset, $blockSize);
            }
            if (null === $chunk) {
                return null;
            }
            $out = $out.$chunk;
            $total = $total + \strlen($chunk);
            $offset = $offset + $blockSize;
            if ($lastBlock) {
                break;
            }
        }

        if ($declaredSize >= 0 && $total !== $declaredSize) {
            return null;
        }
        if ($contentChecksum) {
            if ($offset + 4 > $len) {
                return null;
            }
        }

        return $out;
    }

    private static function byteAt(string $data, int $offset): int
    {
        return \ord(\substr($data, $offset, 1));
    }

    private static function frameHeaderDescriptor(int $contentSize): string
    {
        $fcsFlag = self::contentSizeFlag($contentSize);
        $descriptor = ($fcsFlag & 0x03) | 0x04;

        return \chr($descriptor);
    }

    private static function frameContentSizeField(int $contentSize): string
    {
        $fcsFlag = self::contentSizeFlag($contentSize);
        if (1 === $fcsFlag) {
            return \chr($contentSize);
        }
        if (2 === $fcsFlag) {
            return self::packLittleEndianUInt($contentSize, 2);
        }
        if (3 === $fcsFlag) {
            return self::packLittleEndianUInt($contentSize, 4);
        }
        if (4 === $fcsFlag) {
            return self::packLittleEndianUInt($contentSize, 8);
        }

        return '';
    }

    private static function contentSizeFlag(int $size): int
    {
        if ($size < 256) {
            return 1;
        }
        if ($size < 65536) {
            return 2;
        }
        if ($size < 0x100000000) {
            return 3;
        }

        return 4;
    }

    private static function dictionaryIdSize(int $firstByte): int
    {
        if (0 === $firstByte) {
            return 1;
        }
        if ($firstByte < 256) {
            return 1;
        }

        return 4;
    }

    private static function variableFieldSize(int $fcsFlag): int
    {
        if (1 === $fcsFlag) {
            return 1;
        }
        if (2 === $fcsFlag) {
            return 2;
        }
        if (3 === $fcsFlag) {
            return 4;
        }
        if (4 === $fcsFlag) {
            return 8;
        }

        return 0;
    }

    private static function encodeBlock(string $payload, bool $lastBlock): string
    {
        $size = \strlen($payload);
        $header = ($size << 3) | (self::BLOCK_TYPE_RAW << 1);
        if ($lastBlock) {
            $header = $header | 0x01;
        }

        return self::packLittleEndianUInt($header, 3).$payload;
    }

    private static function decodeRawBlock(string $data, int $offset, int $size): ?string
    {
        if ($size < 0) {
            return null;
        }

        return \substr($data, $offset, $size);
    }

    private static function decodeRleBlock(string $data, int $offset, int $size): ?string
    {
        if ($size < 1 || $offset >= \strlen($data)) {
            return null;
        }
        $byte = $data[$offset];

        return \str_repeat($byte, $size);
    }

    private static function readLittleEndianUInt(string $data, int $offset, int $bytes): int
    {
        $value = 0;
        for ($i = 0; $i < $bytes; $i = $i + 1) {
            $shift = 8 * $i;
            $value = $value + (self::byteAt($data, $offset + $i) << $shift);
        }

        return $value;
    }

    private static function packLittleEndianUInt(int $value, int $bytes): string
    {
        $out = '';
        for ($i = 0; $i < $bytes; $i = $i + 1) {
            $out = $out.\chr($value & 0xFF);
            $value = (int) ($value / 256);
        }

        return $out;
    }
}
