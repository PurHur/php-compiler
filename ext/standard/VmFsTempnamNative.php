<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc mkstemp(3) for tempnam() without host PHP delegation (#4401).
 */
final class VmFsTempnamNative
{
    private const PATH_MAX = 4096;

    private static ?\FFI $ffi = null;

    public static function mkstemp(string $dir, string $prefix): string|false
    {
        if (str_contains($dir, "\0") || str_contains($prefix, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $sep = ('/' === $dir[\strlen($dir) - 1] || '\\' === $dir[\strlen($dir) - 1]) ? '' : '/';
        $template = $dir.$sep.$prefix.'XXXXXX';
        if (\strlen($template) >= self::PATH_MAX) {
            return false;
        }
        try {
            $len = \strlen($template);
            $buf = $ffi->new('char['.($len + 1).']', false);
            for ($i = 0; $i < $len; ++$i) {
                $buf[$i] = $template[$i];
            }
            $buf[$len] = "\0";
            $fd = (int) $ffi->mkstemp(\FFI::addr($buf[0]));
            if ($fd < 0) {
                return false;
            }
            $ffi->close($fd);

            return \FFI::string(\FFI::addr($buf[0]));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return null;
        }

        $cdef = <<<'CDEF'
int mkstemp(char *template);
int close(int fd);
int unlink(const char *pathname);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * mkstemp(3) leaving the descriptor open — tmpfile() plainfile parity (#11397).
     *
     * @return array{0: int, 1: string}|false
     */
    public static function mkstempOpen(string $dir, string $prefix = 'php'): array|false
    {
        if (str_contains($dir, "\0") || str_contains($prefix, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $sep = ('/' === $dir[\strlen($dir) - 1] || '\\' === $dir[\strlen($dir) - 1]) ? '' : '/';
        $template = $dir.$sep.$prefix.'XXXXXX';
        if (\strlen($template) >= self::PATH_MAX) {
            return false;
        }
        try {
            $len = \strlen($template);
            $buf = $ffi->new('char['.($len + 1).']', false);
            for ($i = 0; $i < $len; ++$i) {
                $buf[$i] = $template[$i];
            }
            $buf[$len] = "\0";
            $fd = (int) $ffi->mkstemp(\FFI::addr($buf[0]));
            if ($fd < 0) {
                return false;
            }

            return [$fd, \FFI::string(\FFI::addr($buf[0]))];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function unlinkPath(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        try {
            return 0 === (int) $ffi->unlink($path);
        } catch (\Throwable) {
            return false;
        }
    }
}
