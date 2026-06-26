<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * VM bzcompress()/bzdecompress() — pure PHP via {@see VmBz2Core} (#8868, #3402).
 *
 * Optional libbz2 FFI fast path when PHP_COMPILER_BZ2_FFI=1.
 */
final class VmBz2Native
{
    private const BZ_OK = 0;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return VmBz2Core::available();
    }

    public static function compress(string $source, int $blockSize100k = 4, int $workFactor = 0): string|false
    {
        if (self::ffiEnabled()) {
            $result = self::ffiCompress($source, $blockSize100k, $workFactor);
            if (false !== $result) {
                return $result;
            }
        }

        return VmBz2Core::compress($source, $blockSize100k, $workFactor);
    }

    public static function decompress(string $source, int $small = 0): string|false
    {
        if (self::ffiEnabled()) {
            $result = self::ffiDecompress($source, $small);
            if (false !== $result) {
                return $result;
            }
        }

        return VmBz2Core::decompress($source, $small);
    }

    private static function ffiEnabled(): bool
    {
        $flag = \getenv('PHP_COMPILER_BZ2_FFI');

        return false !== $flag && '1' === $flag;
    }

    private static function ffiCompress(string $source, int $blockSize100k, int $workFactor): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if ($blockSize100k < 1 || $blockSize100k > 9) {
            return false;
        }
        if ($workFactor < 0 || $workFactor > 250) {
            return false;
        }

        $sourceLen = \strlen($source);
        $destLen = $sourceLen + (int) ($sourceLen / 100) + 600;
        if ($destLen <= 0) {
            return false;
        }

        $dest = $ffi->new('char['.$destLen.']');
        $destLenVar = $ffi->new('unsigned int');
        $destLenVar->cdata = $destLen;
        $src = self::copyBytes($ffi, $source);
        $rc = (int) $ffi->BZ2_bzBuffToBuffCompress(
            $dest,
            \FFI::addr($destLenVar),
            $src,
            $sourceLen,
            $blockSize100k,
            0,
            $workFactor
        );
        if (self::BZ_OK !== $rc) {
            return false;
        }
        $written = (int) $destLenVar->cdata;
        if ($written <= 0) {
            return false;
        }

        return \FFI::string($dest, $written);
    }

    private static function ffiDecompress(string $source, int $small): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if ($small < 0 || $small > 1) {
            return false;
        }

        $sourceLen = \strlen($source);
        if (0 === $sourceLen) {
            return '';
        }

        $destLen = \max(64, $sourceLen * 2);
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $dest = $ffi->new('char['.$destLen.']');
            $destLenVar = $ffi->new('unsigned int');
            $destLenVar->cdata = $destLen;
            $src = self::copyBytes($ffi, $source);
            $rc = (int) $ffi->BZ2_bzBuffToBuffDecompress(
                $dest,
                \FFI::addr($destLenVar),
                $src,
                $sourceLen,
                $small,
                0
            );
            if (self::BZ_OK === $rc) {
                $written = (int) $destLenVar->cdata;
                if ($written < 0) {
                    return false;
                }

                return \FFI::string($dest, $written);
            }
            if (-8 !== $rc) {
                return false;
            }
            $destLen *= 2;
        }

        return false;
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
int BZ2_bzBuffToBuffCompress(char *dest, unsigned int *destLen, char *source, unsigned int sourceLen, int blockSize100k, int verbosity, int workFactor);
int BZ2_bzBuffToBuffDecompress(char *dest, unsigned int *destLen, char *source, unsigned int sourceLen, int small, int verbosity);
CDEF;

        foreach (['libbz2.so.1.0', 'libbz2.so.1', 'libbz2.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function copyBytes(\FFI $ffi, string $data): \FFI\CData
    {
        $len = \strlen($data);
        if (0 === $len) {
            return $ffi->new('char[1]');
        }
        $buf = $ffi->new('char['.$len.']', false);
        \FFI::memcpy($buf, $data, $len);

        return $buf;
    }
}
