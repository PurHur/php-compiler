<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

/**
 * VM brotli_compress()/brotli_uncompress() via libbrotli FFI (kjdev/php-ext-brotli, issue #6814).
 *
 * Thin FFI to libbrotlienc/libbrotlidec — no codec logic in runtime/ C.
 */
final class VmBrotliNative
{
    public const DEFAULT_QUALITY = 11;

    public const DEFAULT_LGWIN = 22;

    public const MODE_GENERIC = 0;

    public const MODE_TEXT = 1;

    public const MODE_FONT = 2;

    public const MIN_QUALITY = 0;

    public const MAX_QUALITY = 11;

    private const MIN_LGWIN = 10;

    private const MAX_LGWIN = 24;

    private static ?\FFI $encoderFfi = null;

    private static ?\FFI $decoderFfi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::encoderFfi() && null !== self::decoderFfi();
    }

    public static function compress(string $data, int $quality = self::DEFAULT_QUALITY, int $mode = self::MODE_GENERIC): string|false
    {
        $enc = self::encoderFfi();
        if (null === $enc) {
            return false;
        }
        if ($quality < self::MIN_QUALITY || $quality > self::MAX_QUALITY) {
            return false;
        }
        if ($mode < self::MODE_GENERIC || $mode > self::MODE_FONT) {
            return false;
        }

        $inputLen = \strlen($data);
        if (0 === $inputLen) {
            return self::compressEmpty($enc);
        }

        $inBuf = \FFI::new('uint8_t['.$inputLen.']');
        \FFI::memcpy($inBuf, $data, $inputLen);

        $maxOut = (int) $enc->BrotliEncoderMaxCompressedSize($inputLen);
        if ($maxOut < 1) {
            $maxOut = 64;
        }

        $encodedSize = \FFI::new('size_t');
        $encodedSize->cdata = $maxOut;
        $outBuf = \FFI::new('uint8_t['.$maxOut.']');

        $ok = (int) $enc->BrotliEncoderCompress(
            $quality,
            self::DEFAULT_LGWIN,
            $mode,
            $inputLen,
            $inBuf,
            \FFI::addr($encodedSize),
            $outBuf
        );
        if (1 !== $ok) {
            return false;
        }

        $used = (int) $encodedSize->cdata;
        if ($used < 1) {
            return false;
        }

        return \FFI::string($outBuf, $used);
    }

    public static function uncompress(string $data): string|false
    {
        $dec = self::decoderFfi();
        if (null === $dec) {
            return false;
        }

        $encodedLen = \strlen($data);
        if (0 === $encodedLen) {
            return '';
        }

        $inBuf = \FFI::new('uint8_t['.$encodedLen.']');
        \FFI::memcpy($inBuf, $data, $encodedLen);

        $decodedSize = \FFI::new('size_t');
        $decodedSize->cdata = $encodedLen * 16;
        if ($decodedSize->cdata < 64) {
            $decodedSize->cdata = 64;
        }
        $outBuf = \FFI::new('uint8_t['.(int) $decodedSize->cdata.']');

        while (true) {
            $sizeBefore = (int) $decodedSize->cdata;
            $ok = (int) $dec->BrotliDecoderDecompress(
                $encodedLen,
                $inBuf,
                \FFI::addr($decodedSize),
                $outBuf
            );
            if (1 === $ok) {
                $used = (int) $decodedSize->cdata;

                return \FFI::string($outBuf, $used);
            }
            if ($sizeBefore >= 16 * 1024 * 1024) {
                return false;
            }
            $decodedSize->cdata = $sizeBefore * 2;
            $outBuf = \FFI::new('uint8_t['.(int) $decodedSize->cdata.']');
        }
    }

    private static function compressEmpty(\FFI $enc): string|false
    {
        $maxOut = 64;
        $encodedSize = \FFI::new('size_t');
        $encodedSize->cdata = $maxOut;
        $outBuf = \FFI::new('uint8_t['.$maxOut.']');
        $inBuf = \FFI::new('uint8_t[1]');

        $ok = (int) $enc->BrotliEncoderCompress(
            self::DEFAULT_QUALITY,
            self::DEFAULT_LGWIN,
            self::MODE_GENERIC,
            0,
            $inBuf,
            \FFI::addr($encodedSize),
            $outBuf
        );
        if (1 !== $ok) {
            return false;
        }

        return \FFI::string($outBuf, (int) $encodedSize->cdata);
    }

    private static function encoderFfi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$encoderFfi) {
            return self::$encoderFfi;
        }
        if (!self::ffiEnabled()) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int BrotliBOOL;
typedef enum {
    BROTLI_MODE_GENERIC = 0,
    BROTLI_MODE_TEXT = 1,
    BROTLI_MODE_FONT = 2
} BrotliEncoderMode;
size_t BrotliEncoderMaxCompressedSize(size_t input_size);
BrotliBOOL BrotliEncoderCompress(
    int quality,
    int lgwin,
    BrotliEncoderMode mode,
    size_t input_size,
    const uint8_t* input_buffer,
    size_t* encoded_size,
    uint8_t* encoded_buffer);
CDEF;

        foreach (['libbrotlienc.so.1', 'libbrotlienc.so'] as $lib) {
            try {
                self::$encoderFfi = \FFI::cdef($cdef, $lib);

                return self::$encoderFfi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function decoderFfi(): ?\FFI
    {
        if (self::$ffiUnavailable && null === self::$decoderFfi) {
            return null;
        }
        if (null !== self::$decoderFfi) {
            return self::$decoderFfi;
        }
        if (!self::ffiEnabled()) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef int BrotliBOOL;
BrotliBOOL BrotliDecoderDecompress(
    size_t encoded_size,
    const uint8_t* encoded_buffer,
    size_t* decoded_size,
    uint8_t* decoded_buffer);
CDEF;

        foreach (['libbrotlidec.so.1', 'libbrotlidec.so'] as $lib) {
            try {
                self::$decoderFfi = \FFI::cdef($cdef, $lib);

                return self::$decoderFfi;
            } catch (\Throwable) {
            }
        }

        if (null === self::$encoderFfi) {
            self::$ffiUnavailable = true;
        }

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
