<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * VM lzf_* via bundled liblzf FFI (php-src ext/lzf/lzf.c; #6384).
 *
 * Builds/loads {@see third_party/liblzf} — no host ext-lzf or runtime/*.c shim.
 */
final class VmLzfNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function compress(string $data): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inLen = \strlen($data);
        if (0 === $inLen) {
            return '';
        }

        $outCap = $inLen + (int) ($inLen / 64) + 16;
        $src = self::copyBytes($ffi, $data);
        $dst = $ffi->new('unsigned char['.$outCap.']');
        $written = (int) $ffi->lzf_compress($src, $inLen, $dst, $outCap);
        if ($written <= 0) {
            return false;
        }

        return \FFI::string($dst, $written);
    }

    public static function decompress(string $data): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inLen = \strlen($data);
        if (0 === $inLen) {
            return '';
        }

        $src = self::copyBytes($ffi, $data);
        $outCap = \max(64, $inLen * 8);
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $dst = $ffi->new('unsigned char['.$outCap.']');
            $written = (int) $ffi->lzf_decompress($src, $inLen, $dst, $outCap);
            if ($written > 0) {
                return \FFI::string($dst, $written);
            }
            $outCap *= 2;
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

        $lib = self::resolveLibraryPath();
        if (null === $lib) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
unsigned int lzf_compress(const void *in_data, unsigned int in_len, void *out_data, unsigned int out_len);
unsigned int lzf_decompress(const void *in_data, unsigned int in_len, void *out_data, unsigned int out_len);
CDEF;

        try {
            self::$ffi = \FFI::cdef($cdef, $lib);

            return self::$ffi;
        } catch (\Throwable) {
            self::$ffiUnavailable = true;

            return null;
        }
    }

    private static function resolveLibraryPath(): ?string
    {
        $root = \dirname(__DIR__, 2);
        $candidates = [
            $root.'/.libs/liblzf.so',
            $root.'/third_party/liblzf/liblzf.so',
        ];
        foreach ($candidates as $path) {
            if (\is_file($path)) {
                return $path;
            }
        }

        $buildScript = $root.'/script/build-liblzf.sh';
        if (\is_file($buildScript) && self::canBuildSharedLibrary()) {
            $cmd = 'bash '.escapeshellarg($buildScript).' 2>/dev/null';
            @\shell_exec($cmd);
            foreach ($candidates as $path) {
                if (\is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private static function canBuildSharedLibrary(): bool
    {
        static $checked = null;
        if (null !== $checked) {
            return $checked;
        }
        $checked = '' !== (string) @\shell_exec('command -v gcc 2>/dev/null');

        return $checked;
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
