<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc open(2)/dup(2) for VM fopen(); falls back to {@see VmFsOpenPure} when FFI unavailable (#8950).
 *
 * php-src: ext/standard/streams.c — _php_stream_fopen
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\StreamIoJit} / __compiler_fopen (unchanged)
 */
final class VmFsOpenNative
{
    private const O_RDONLY = 0;

    private const O_WRONLY = 1;

    private const O_RDWR = 2;

    private const O_CREAT = 64;

    private const O_EXCL = 128;

    private const O_TRUNC = 512;

    private const O_APPEND = 1024;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsOpenPure::available();
    }

    /**
     * @return int|false VM fd stream handle
     */
    public static function open(string $path, string $mode)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $parsed = self::modeToOpenFlags($mode);
        if (null === $parsed) {
            return false;
        }
        [$flags, $createMode] = $parsed;
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsOpenPure::open($path, $mode);
        }

        try {
            $fd = 0 !== ($flags & self::O_CREAT)
                ? (int) $ffi->open($path, $flags, $createMode)
                : (int) $ffi->open($path, $flags);
            if ($fd < 0) {
                return false;
            }

            $dupFd = (int) $ffi->dup($fd);
            $ffi->close($fd);
            if ($dupFd < 0) {
                return false;
            }

            $streamMode = self::phpStreamMode($mode);
            $handle = VmPhpFdStream::adopt($dupFd, $path, $streamMode);
            if (false === $handle) {
                $ffi->close($dupFd);

                return false;
            }

            return $handle;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: int, 1: int}|null [open flags, create mode]
     */
    private static function modeToOpenFlags(string $mode): ?array
    {
        $stripped = str_replace(['t', 'b'], '', $mode);
        if ('' === $stripped) {
            return null;
        }
        $plus = str_ends_with($stripped, '+');
        if ($plus) {
            $stripped = substr($stripped, 0, -1);
        }
        if ('' === $stripped) {
            return null;
        }

        return match ($stripped[0]) {
            'r' => $plus ? [self::O_RDWR, 0666] : [self::O_RDONLY, 0],
            'w' => $plus
                ? [self::O_RDWR | self::O_CREAT | self::O_TRUNC, 0666]
                : [self::O_WRONLY | self::O_CREAT | self::O_TRUNC, 0666],
            'a' => $plus
                ? [self::O_RDWR | self::O_CREAT | self::O_APPEND, 0666]
                : [self::O_WRONLY | self::O_CREAT | self::O_APPEND, 0666],
            'x' => $plus
                ? [self::O_RDWR | self::O_CREAT | self::O_EXCL, 0666]
                : [self::O_WRONLY | self::O_CREAT | self::O_EXCL, 0666],
            'c' => $plus
                ? [self::O_RDWR | self::O_CREAT, 0666]
                : [self::O_WRONLY | self::O_CREAT, 0666],
            default => null,
        };
    }

    private static function phpStreamMode(string $mode): string
    {
        if ('' === $mode) {
            return 'rb';
        }
        if (!str_contains($mode, 'b')) {
            return $mode.'b';
        }

        return $mode;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
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
int open(const char *pathname, int flags, ...);
int dup(int oldfd);
int close(int fd);
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
