<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc popen(3)/pclose(3) for VM; falls back to {@see VmPopenPure} when FFI unavailable (#8951).
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(popen), PHP_FUNCTION(pclose)
 * JIT/AOT: {@see JitPopen} / __compiler_popen via StreamIoJit.
 */
final class VmPopenNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmPopenPure::available();
    }

    /**
     * @return array{handle: int, file: int|\FFI\CData}|false
     */
    public static function open(string $command, string $mode): array|false
    {
        if (str_contains($command, "\0")) {
            return false;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return VmPopenPure::open($command, $mode);
        }

        try {
            $libcFp = $ffi->popen($command, $mode);
            if (null === $libcFp) {
                return false;
            }

            $fd = (int) $ffi->fileno($libcFp);
            if ($fd < 0) {
                $ffi->pclose($libcFp);

                return false;
            }

            $dupFd = (int) $ffi->dup($fd);
            if ($dupFd < 0) {
                $ffi->pclose($libcFp);

                return false;
            }

            $phpMode = self::phpStreamMode($mode);
            $uri = 'popen://'.$command;
            $handle = VmPhpFdStream::adopt($dupFd, $uri, $phpMode);
            if (false === $handle) {
                $ffi->close($dupFd);
                $ffi->pclose($libcFp);

                return false;
            }

            return ['handle' => $handle, 'file' => $libcFp];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function pclose(int|\FFI\CData $token): int
    {
        if (\is_int($token)) {
            return VmPopenPure::pclose($token);
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        try {
            $result = (int) $ffi->pclose($token);

            return -1 === $result ? -1 : $result;
        } catch (\Throwable) {
            return -1;
        }
    }

    private static function phpStreamMode(string $mode): string
    {
        $read = str_contains($mode, 'r');
        $write = str_contains($mode, 'w');
        $append = str_contains($mode, 'a');
        $plus = str_contains($mode, '+');

        if ($plus) {
            if ($append) {
                return 'a+';
            }
            if ($write) {
                return 'w+';
            }

            return 'r+';
        }
        if ($write || $append) {
            return $append ? 'a' : 'w';
        }

        return 'r';
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
typedef struct _IO_FILE FILE;
FILE *popen(const char *command, const char *type);
int pclose(FILE *stream);
int fileno(FILE *stream);
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
