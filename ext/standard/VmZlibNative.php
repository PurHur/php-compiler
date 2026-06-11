<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM zlib via libz FFI (issue #6476, #6791).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringZlibJit} — no host \gzcompress delegation.
 *
 * @see https://github.com/php/php-src/blob/master/ext/zlib/zlib.c php_zlib_encode / php_zlib_decode
 */
final class VmZlibNative
{
    private const Z_OK = 0;

    private const Z_STREAM_END = 1;

    private const Z_DEFLATED = 8;

    private const Z_DEFAULT_STRATEGY = 0;

    private const Z_FINISH = 4;

    private const Z_DEFAULT_COMPRESSION = -1;

    private const ENCODING_RAW = 65534;

    private const ENCODING_DEFLATE = 65535;

    private const ENCODING_GZIP = 16;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function gzcompress(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_DEFLATE): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $level = self::normalizeLevel($level);

        if (self::isDeflateEncoding($encoding) || (!self::isRawEncoding($encoding) && !self::isGzipEncoding($encoding))) {
            return self::compress2($ffi, $data, $level);
        }
        if (self::isGzipEncoding($encoding)) {
            return self::deflateBytes($ffi, $data, $level, 31);
        }

        return self::deflateBytes($ffi, $data, $level, -15);
    }

    public static function gzuncompress(string $data, int $maxLength = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return self::uncompress($ffi, $data, $maxLength);
    }

    public static function gzdeflate(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_RAW): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $level = self::normalizeLevel($level);
        $windowBits = -15;
        if (self::isGzipEncoding($encoding)) {
            $windowBits = 31;
        } elseif (self::isDeflateEncoding($encoding)) {
            $windowBits = 15;
        } elseif (self::isRawEncoding($encoding)) {
            $windowBits = -15;
        }

        return self::deflateBytes($ffi, $data, $level, $windowBits);
    }

    public static function gzinflate(string $data, int $maxLength = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return self::inflateBytes($ffi, $data, -15, $maxLength);
    }

    public static function gzencode(string $data, int $level = -1, int $encoding = \ZLIB_ENCODING_GZIP): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $level = self::normalizeLevel($level);
        $windowBits = 31;
        if (self::isRawEncoding($encoding)) {
            $windowBits = -15;
        } elseif (self::isDeflateEncoding($encoding)) {
            $windowBits = 15;
        } elseif (self::isGzipEncoding($encoding)) {
            $windowBits = 31;
        }

        return self::deflateBytes($ffi, $data, $level, $windowBits);
    }

    public static function gzdecode(string $data, int $maxLength = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return self::inflateBytes($ffi, $data, 31, $maxLength);
    }

    /** php_zlib_encode() — deflateInit2 windowBits from encoding (ext/zlib/zlib.c, issue #6288). */
    public static function zlib_encode(string $data, int $encoding, int $level = -1): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $windowBits = self::encodingToWindowBits($encoding);
        if (null === $windowBits) {
            return false;
        }

        return self::deflateBytes($ffi, $data, self::normalizeLevel($level), $windowBits);
    }

    /** php_zlib_decode() — auto-detect then raw retry (ext/zlib/zlib.c, issue #6288). */
    public static function zlib_decode(string $data, int $maxLength = 0): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $result = self::inflateBytes($ffi, $data, 47, $maxLength);
        if (false !== $result) {
            return $result;
        }

        return self::inflateBytes($ffi, $data, -15, $maxLength);
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef unsigned char Bytef;
typedef unsigned int uInt;
typedef unsigned long uLong;
typedef unsigned long long uLongf;
typedef void *voidpf;
typedef struct z_stream_s {
    Bytef *next_in;
    uInt avail_in;
    uLong total_in;
    Bytef *next_out;
    uInt avail_out;
    uLong total_out;
    char *msg;
    struct internal_state *state;
    void *(*zalloc)(voidpf opaque, uInt items, uInt size);
    void (*zfree)(voidpf opaque, voidpf address);
    voidpf opaque;
    int data_type;
    uLong adler;
    uLong reserved;
} z_stream;
int compress2(Bytef *dest, uLongf *destLen, const Bytef *source, uLong sourceLen, int level);
int uncompress(Bytef *dest, uLongf *destLen, const Bytef *source, uLong sourceLen);
uLong compressBound(uLong sourceLen);
int deflateInit2_(z_stream *strm, int level, int method, int windowBits, int memLevel, int strategy, const char *version, int stream_size);
int deflate(z_stream *strm, int flush);
int deflateEnd(z_stream *strm);
uLong deflateBound(z_stream *strm, uLong sourceLen);
int inflateInit2_(z_stream *strm, int windowBits, const char *version, int stream_size);
int inflate(z_stream *strm, int flush);
int inflateEnd(z_stream *strm);
CDEF;

        foreach (['libz.so.1', 'libz.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function normalizeLevel(int $level): int
    {
        if ($level < self::Z_DEFAULT_COMPRESSION) {
            return self::Z_DEFAULT_COMPRESSION;
        }
        if ($level > 9) {
            return 9;
        }

        return $level;
    }

    private static function isGzipEncoding(int $encoding): bool
    {
        return self::ENCODING_GZIP === $encoding || 31 === $encoding;
    }

    private static function isRawEncoding(int $encoding): bool
    {
        return self::ENCODING_RAW === $encoding || -15 === $encoding;
    }

    private static function isDeflateEncoding(int $encoding): bool
    {
        return self::ENCODING_DEFLATE === $encoding || -16 === $encoding || 15 === $encoding;
    }

    private static function encodingToWindowBits(int $encoding): ?int
    {
        if (self::isGzipEncoding($encoding)) {
            return 31;
        }
        if (self::isDeflateEncoding($encoding)) {
            return 15;
        }
        if (self::isRawEncoding($encoding)) {
            return -15;
        }

        return null;
    }

    private static function compress2(\FFI $ffi, string $data, int $level): string|false
    {
        $len = \strlen($data);
        if (0 === $len) {
            $bound = (int) $ffi->compressBound(0);
        } else {
            $bound = (int) $ffi->compressBound($len);
        }
        $outLen = $ffi->new('uLongf');
        $outLen->cdata = $bound;
        $out = $ffi->new('unsigned char['.$bound.']');
        $src = self::copyBytes($ffi, $data);
        $rc = $ffi->compress2(
            $out,
            \FFI::addr($outLen),
            $src,
            $len,
            $level
        );
        if (self::Z_OK !== $rc) {
            return false;
        }

        return \FFI::string($out, (int) $outLen->cdata);
    }

    private static function uncompress(\FFI $ffi, string $data, int $maxLength): string|false
    {
        $len = \strlen($data);
        $outLenInit = $len < 64 ? 64 : $len * 4;
        if ($maxLength > 0 && $maxLength < $outLenInit) {
            $outLenInit = $maxLength;
        }
        $outLen = $ffi->new('uLongf');
        $outLen->cdata = $outLenInit;
        $out = $ffi->new('unsigned char['.$outLenInit.']');
        $src = self::copyBytes($ffi, $data);
        $rc = $ffi->uncompress(
            $out,
            \FFI::addr($outLen),
            $src,
            $len
        );
        if (self::Z_OK !== $rc) {
            return false;
        }
        $finalLen = (int) $outLen->cdata;
        if ($maxLength > 0 && $finalLen > $maxLength) {
            return false;
        }

        return \FFI::string($out, $finalLen);
    }

    private static function deflateBytes(\FFI $ffi, string $data, int $level, int $windowBits): string|false
    {
        $z = $ffi->new('z_stream');
        \FFI::memset($z, 0, \FFI::sizeof($z));
        $rc = $ffi->deflateInit2_(
            \FFI::addr($z),
            $level,
            self::Z_DEFLATED,
            $windowBits,
            8,
            self::Z_DEFAULT_STRATEGY,
            '1.2.11',
            \FFI::sizeof($z)
        );
        if (self::Z_OK !== $rc) {
            return false;
        }

        $inLen = \strlen($data);
        $bound = (int) $ffi->deflateBound(\FFI::addr($z), $inLen);
        $outCap = $bound < 64 ? 64 : $bound;
        $out = $ffi->new('unsigned char['.$outCap.']');
        $in = self::copyBytes($ffi, $data);

        $z->next_in = \FFI::addr($in[0]);
        $z->avail_in = $inLen;
        $z->next_out = \FFI::addr($out[0]);
        $z->avail_out = $outCap;

        $status = $ffi->deflate(\FFI::addr($z), self::Z_FINISH);
        $availOut = (int) $z->avail_out;
        $outLen = $outCap - $availOut;
        $ffi->deflateEnd(\FFI::addr($z));

        if (self::Z_STREAM_END !== $status) {
            return false;
        }

        return \FFI::string($out, $outLen);
    }

    private static function inflateBytes(\FFI $ffi, string $data, int $windowBits, int $maxLength): string|false
    {
        $z = $ffi->new('z_stream');
        \FFI::memset($z, 0, \FFI::sizeof($z));
        $rc = $ffi->inflateInit2_(\FFI::addr($z), $windowBits, '1.2.11', \FFI::sizeof($z));
        if (self::Z_OK !== $rc) {
            return false;
        }

        $inLen = \strlen($data);
        $outCap = $inLen < 64 ? 64 : $inLen * 4;
        if ($maxLength > 0 && $maxLength < $outCap) {
            $outCap = $maxLength;
        }
        $out = $ffi->new('unsigned char['.$outCap.']');
        $in = self::copyBytes($ffi, $data);

        $z->next_in = \FFI::addr($in[0]);
        $z->avail_in = $inLen;
        $z->next_out = \FFI::addr($out[0]);
        $z->avail_out = $outCap;

        $status = $ffi->inflate(\FFI::addr($z), self::Z_FINISH);
        $availOut = (int) $z->avail_out;
        $outLen = $outCap - $availOut;
        $ffi->inflateEnd(\FFI::addr($z));

        if (self::Z_STREAM_END !== $status && self::Z_OK !== $status) {
            return false;
        }
        if (self::Z_OK === $status && $availOut === $outCap) {
            return false;
        }
        if ($maxLength > 0 && $outLen > $maxLength) {
            return false;
        }

        return \FFI::string($out, $outLen);
    }

    private static function copyBytes(\FFI $ffi, string $data): \FFI\CData
    {
        $len = \strlen($data);
        if (0 === $len) {
            return $ffi->new('unsigned char[1]');
        }
        $buf = $ffi->new('unsigned char['.$len.']', false);
        \FFI::memcpy($buf, $data, $len);

        return $buf;
    }
}
