<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

/**
 * VM lz4_*() via liblz4 FFI (kjdev/php-ext-lz4; #22529, #27883).
 *
 * Block wire format matches PECL: 4-byte little-endian uncompressed length + LZ4 block.
 * Frame codecs use LZ4F_* from the same library. Thin FFI only — no codec logic in runtime/ C.
 */
final class VmLz4Native
{
    public const CLEVEL_MIN = 3;

    public const CLEVEL_MAX = 12;

    public const CHECKSUM_FRAME = 1;

    public const CHECKSUM_BLOCK = 2;

    public const BLOCK_SIZE_64KB = 4;

    public const BLOCK_SIZE_256KB = 5;

    public const BLOCK_SIZE_1MB = 6;

    public const BLOCK_SIZE_4MB = 7;

    /** LZ4F_VERSION from lz4frame.h (stable ABI selector for createDecompressionContext). */
    private const LZ4F_VERSION = 100;

    private const LZ4F_HEADER_SIZE_MAX = 19;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function versionNumber(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        return (int) $ffi->LZ4_versionNumber();
    }

    public static function versionText(): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return '0.0.0';
        }
        $raw = $ffi->LZ4_versionString();
        if (\is_string($raw)) {
            return $raw;
        }

        return \FFI::string($raw);
    }

    public static function compress(string $data, int $level = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (0 !== $level && ($level < self::CLEVEL_MIN || $level > self::CLEVEL_MAX)) {
            return false;
        }

        $inLen = \strlen($data);
        $bound = (int) $ffi->LZ4_compressBound($inLen);
        if ($bound < 1) {
            $bound = 16;
        }
        $header = 4;
        $payloadCap = $bound + 1;
        $outCap = $header + $payloadCap;

        $out = \FFI::new('char['.$outCap.']');
        $headerBytes = \pack('V', $inLen);
        \FFI::memcpy($out, $headerBytes, $header);

        $inBuf = \FFI::new('char['.\max(1, $inLen).']');
        if ($inLen > 0) {
            \FFI::memcpy($inBuf, $data, $inLen);
        }

        $dst = \FFI::addr($out[$header]);
        $src = \FFI::addr($inBuf[0]);

        if (0 === $level) {
            $written = (int) $ffi->LZ4_compress_default(
                $src,
                $dst,
                $inLen,
                $payloadCap
            );
        } else {
            $written = (int) $ffi->LZ4_compress_HC(
                $src,
                $dst,
                $inLen,
                $payloadCap,
                $level
            );
        }
        if ($written <= 0) {
            return false;
        }

        return \FFI::string($out, $header + $written);
    }

    public static function uncompress(string $data, int $max = -1, int $offset = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inLen = \strlen($data);
        if ($max > 0) {
            $varLen = $max;
            if (0 === $offset) {
                $offset = 4;
            }
        } else {
            if ($inLen < 4) {
                return false;
            }
            $offset = 4;
            $unpacked = \unpack('V', \substr($data, 0, 4));
            if (false === $unpacked) {
                return false;
            }
            $varLen = (int) $unpacked[1];
            if ($varLen < 0) {
                return false;
            }
        }

        if ($offset < 0 || $offset > $inLen) {
            return false;
        }
        $compLen = $inLen - $offset;
        if ($compLen < 0) {
            return false;
        }

        if (0 === $varLen) {
            return '';
        }

        $comp = \substr($data, $offset);
        $inBuf = \FFI::new('char['.\max(1, $compLen).']');
        if ($compLen > 0) {
            \FFI::memcpy($inBuf, $comp, $compLen);
        }
        $outBuf = \FFI::new('char['.$varLen.']');

        $decoded = (int) $ffi->LZ4_decompress_safe(
            \FFI::addr($inBuf[0]),
            \FFI::addr($outBuf[0]),
            $compLen,
            $varLen
        );
        if ($decoded <= 0) {
            return false;
        }

        return \FFI::string($outBuf, $decoded);
    }

    /**
     * lz4_compress_frame() — LZ4F frame format (kjdev/php-ext-lz4; #27883).
     *
     * @param int $maxBlockSize LZ4_BLOCK_SIZE_* (4–7); other values → default 64KB
     * @param int $checksums bitmask of CHECKSUM_FRAME / CHECKSUM_BLOCK
     */
    public static function compressFrame(
        string $data,
        int $level = 0,
        int $maxBlockSize = 0,
        int $checksums = 0,
    ): string|false {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (0 !== $level && ($level < self::CLEVEL_MIN || $level > self::CLEVEL_MAX)) {
            return false;
        }

        $inLen = \strlen($data);
        if ($maxBlockSize < self::BLOCK_SIZE_64KB || $maxBlockSize > self::BLOCK_SIZE_4MB) {
            $maxBlockSize = 0;
        }

        $prefs = $ffi->new('LZ4F_preferences_t');
        \FFI::memset($prefs, 0, \FFI::sizeof($prefs));
        $prefs->frameInfo->blockSizeID = $maxBlockSize;
        $prefs->frameInfo->contentSize = $inLen;
        $prefs->frameInfo->contentChecksumFlag = ($checksums & self::CHECKSUM_FRAME) > 0 ? 1 : 0;
        $prefs->frameInfo->blockChecksumFlag = ($checksums & self::CHECKSUM_BLOCK) > 0 ? 1 : 0;
        $prefs->compressionLevel = $level;

        $bound = (int) $ffi->LZ4F_compressFrameBound($inLen, \FFI::addr($prefs));
        if ($bound < 1) {
            return false;
        }

        $out = \FFI::new('char['.$bound.']');
        $inBuf = \FFI::new('char['.\max(1, $inLen).']');
        if ($inLen > 0) {
            \FFI::memcpy($inBuf, $data, $inLen);
        }

        $written = (int) $ffi->LZ4F_compressFrame(
            $out,
            $bound,
            \FFI::addr($inBuf[0]),
            $inLen,
            \FFI::addr($prefs)
        );
        if (0 !== (int) $ffi->LZ4F_isError($written) || $written <= 0) {
            return false;
        }

        return \FFI::string($out, $written);
    }

    /** lz4_uncompress_frame() — LZ4F frame format (kjdev/php-ext-lz4; #27883). */
    public static function uncompressFrame(string $data): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inLen = \strlen($data);
        if ($inLen < 1) {
            return false;
        }

        $dctxArr = \FFI::new('void*[1]');
        $err = (int) $ffi->LZ4F_createDecompressionContext($dctxArr, self::LZ4F_VERSION);
        if (0 !== (int) $ffi->LZ4F_isError($err)) {
            return false;
        }
        $dctx = $dctxArr[0];

        $inBuf = \FFI::new('char['.\max(1, $inLen).']');
        \FFI::memcpy($inBuf, $data, $inLen);

        $frameInfo = $ffi->new('LZ4F_frameInfo_t');
        $srcSize = \FFI::new('size_t');
        $srcSize->cdata = \min(self::LZ4F_HEADER_SIZE_MAX, $inLen);
        $next = (int) $ffi->LZ4F_getFrameInfo(
            $dctx,
            \FFI::addr($frameInfo),
            \FFI::addr($inBuf[0]),
            \FFI::addr($srcSize)
        );
        if (0 !== (int) $ffi->LZ4F_isError($next)) {
            $ffi->LZ4F_freeDecompressionContext($dctx);

            return false;
        }

        $blockSize = self::blockSizeFromId((int) $frameInfo->blockSizeID);
        $contentSize = (int) $frameInfo->contentSize;
        $outCap = $contentSize > 0 ? $contentSize : $blockSize;
        if ($outCap < 1) {
            $outCap = $blockSize;
        }

        $dst = \FFI::new('char['.$outCap.']');
        $inOff = (int) $srcSize->cdata;
        $written = 0;

        while ($next > 0) {
            if (0 === $contentSize && ($outCap - $written) < $blockSize) {
                $outCap += $blockSize * 3;
                $grown = \FFI::new('char['.$outCap.']');
                if ($written > 0) {
                    \FFI::memcpy($grown, $dst, $written);
                }
                $dst = $grown;
            }

            $dstSize = \FFI::new('size_t');
            $dstSize->cdata = $outCap - $written;
            $srcRemain = \FFI::new('size_t');
            $srcRemain->cdata = $inLen - $inOff;

            $next = (int) $ffi->LZ4F_decompress(
                $dctx,
                \FFI::addr($dst[$written]),
                \FFI::addr($dstSize),
                \FFI::addr($inBuf[$inOff]),
                \FFI::addr($srcRemain),
                null
            );
            if (0 !== (int) $ffi->LZ4F_isError($next)) {
                $ffi->LZ4F_freeDecompressionContext($dctx);

                return false;
            }

            $consumed = (int) $srcRemain->cdata;
            if (0 === $consumed) {
                $ffi->LZ4F_freeDecompressionContext($dctx);

                return false;
            }
            $inOff += $consumed;
            $written += (int) $dstSize->cdata;
        }

        $ffi->LZ4F_freeDecompressionContext($dctx);

        return \FFI::string($dst, $written);
    }

    private static function blockSizeFromId(int $blockSizeId): int
    {
        return match ($blockSizeId) {
            self::BLOCK_SIZE_256KB => 1 << 18,
            self::BLOCK_SIZE_1MB => 1 << 20,
            self::BLOCK_SIZE_4MB => 1 << 22,
            default => 1 << 16,
        };
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable && null === self::$ffi) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled()) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
unsigned LZ4_versionNumber(void);
const char* LZ4_versionString(void);
int LZ4_compressBound(int inputSize);
int LZ4_compress_default(const char* src, char* dst, int srcSize, int dstCapacity);
int LZ4_compress_HC(const char* src, char* dst, int srcSize, int dstCapacity, int compressionLevel);
int LZ4_decompress_safe(const char* src, char* dst, int compressedSize, int dstCapacity);
unsigned LZ4F_isError(size_t code);
size_t LZ4F_compressFrameBound(size_t srcSize, const void* prefsPtr);
size_t LZ4F_compressFrame(void* dstBuffer, size_t dstCapacity, const void* srcBuffer, size_t srcSize, const void* preferencesPtr);
unsigned long long LZ4F_createDecompressionContext(void** dctxPtr, unsigned version);
unsigned long long LZ4F_freeDecompressionContext(void* dctx);
typedef struct {
  unsigned blockSizeID;
  unsigned blockMode;
  unsigned contentChecksumFlag;
  unsigned frameType;
  unsigned long long contentSize;
  unsigned dictID;
  unsigned blockChecksumFlag;
} LZ4F_frameInfo_t;
typedef struct {
  LZ4F_frameInfo_t frameInfo;
  int compressionLevel;
  unsigned autoFlush;
  unsigned favorDecSpeed;
  unsigned reserved[3];
} LZ4F_preferences_t;
size_t LZ4F_getFrameInfo(void* dctx, LZ4F_frameInfo_t* frameInfoPtr, const void* srcBuffer, size_t* srcSizePtr);
size_t LZ4F_decompress(void* dctx, void* dstBuffer, size_t* dstSizePtr, const void* srcBuffer, size_t* srcSizePtr, const void* dOptPtr);
CDEF;

        foreach (['liblz4.so.1', 'liblz4.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return \extension_loaded('FFI');
    }
}
