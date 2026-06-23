<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc getcwd for VM without host \getcwd() (issue #5044, #1492).
 *
 * php-src: ext/standard/dir.c — getcwd(2) / realpath fallback.
 * JIT/AOT: {@see JitGetcwd} via realpath(3) on ".".
 */
final class VmGetcwdNative
{
    private const PATH_MAX = 4096;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /**
     * @return string|false
     */
    public static function resolve()
    {
        $viaGetcwd = self::resolveViaFfiGetcwd();
        if (false !== $viaGetcwd) {
            return $viaGetcwd;
        }

        $viaFfi = self::resolveViaFfiRealpath();
        if (false !== $viaFfi) {
            return self::validateCwd($viaFfi);
        }

        if ('Linux' === \PHP_OS_FAMILY) {
            $viaProc = self::resolveLinuxProcCwd();
            if (false === $viaProc) {
                return false;
            }

            return self::validateCwd($viaProc);
        }

        return false;
    }

    /**
     * @return string|false
     */
    private static function resolveViaFfiGetcwd()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $buf = $ffi->new('char['.self::PATH_MAX.']');
            $result = $ffi->getcwd(\FFI::addr($buf[0]), self::PATH_MAX);
            if (null === $result) {
                return false;
            }
            $path = \FFI::string($result);
            if ('' === $path) {
                return false;
            }

            return self::validateCwd($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * php-src getcwd(2) failure / Linux proc "(deleted)" cwd must be false (#10451).
     *
     * @return string|false
     */
    private static function validateCwd(string $path)
    {
        if ('' === $path || str_ends_with($path, ' (deleted)')) {
            return false;
        }
        if (!is_dir($path)) {
            return false;
        }

        return $path;
    }

    /**
     * @return string|false
     */
    private static function resolveViaFfiRealpath()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $dot = $ffi->new('char[2]');
            $dot[0] = '.';
            $dot[1] = "\0";
            $resolved = $ffi->realpath(\FFI::addr($dot[0]), null);
            if (null === $resolved) {
                return false;
            }
            $path = \FFI::string($resolved);
            $ffi->free($resolved);
            if ('' === $path) {
                return false;
            }

            return $path;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Linux bootstrap without host getcwd: /proc/self/cwd symlink (issue #7287 pattern).
     *
     * @return string|false
     */
    private static function resolveLinuxProcCwd()
    {
        if (!\is_link('/proc/self/cwd') && !\is_readable('/proc/self/cwd')) {
            return false;
        }

        $viaFfi = self::resolveViaFfiReadlink('/proc/self/cwd');
        if (false !== $viaFfi) {
            return $viaFfi;
        }

        $target = @\readlink('/proc/self/cwd');
        if (false === $target || '' === $target) {
            return false;
        }

        return $target;
    }

    /**
     * @return string|false
     */
    private static function resolveViaFfiReadlink(string $path)
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $pathC = $ffi->new('char['.(\strlen($path) + 1).']', false);
            \FFI::memcpy($pathC, $path, \strlen($path));
            $pathC[\strlen($path)] = "\0";
            $buf = $ffi->new('char['.self::PATH_MAX.']');
            $len = (int) $ffi->readlink(
                \FFI::addr($pathC[0]),
                \FFI::addr($buf[0]),
                self::PATH_MAX
            );
            if ($len < 0) {
                return false;
            }

            return \FFI::string(\FFI::addr($buf[0]), $len);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
char *getcwd(char *buf, size_t size);
char *realpath(const char *path, char *resolved_path);
ssize_t readlink(const char *pathname, char *buf, size_t bufsiz);
void free(void *ptr);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
