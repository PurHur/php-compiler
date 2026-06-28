<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc FILE* handle table for compiled JIT/AOT embed modules (#9442, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StreamIoJit} phpc_stream_handles[] for lifecycle
 * without duplicating LLVM in {@see StreamLifecycleJit}.
 * php-src: ext/standard/streamsfuncs.c — php_stream resource lifetime
 */
final class StreamLibcHandleJitHelper
{
    public const MAX_HANDLES = 256;

    /** @var array<int, int> opaque FILE* addresses */
    private static array $fpPtr = [];

    /** @var array<int, true> */
    private static array $popen = [];

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function registerFromPtr(int $handle, int $fpPtr): void
    {
        if ($handle <= 0 || $handle >= self::MAX_HANDLES) {
            return;
        }
        if (0 === $fpPtr) {
            unset(self::$fpPtr[$handle], self::$popen[$handle]);

            return;
        }
        self::$fpPtr[$handle] = $fpPtr;
    }

    public static function markPopen(int $handle): void
    {
        if ($handle > 0 && $handle < self::MAX_HANDLES) {
            self::$popen[$handle] = true;
        }
    }

    public static function isOpen(int $handle): bool
    {
        return isset(self::$fpPtr[$handle]);
    }

    /** @return int opaque pointer (0 when closed) for LLVM __phpc_resolve_stream bridge */
    public static function resolvePtr(int $handle): int
    {
        return self::$fpPtr[$handle] ?? 0;
    }

    public static function fclose(int $handle): bool
    {
        if (!isset(self::$fpPtr[$handle])) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $ptr = self::$fpPtr[$handle];
        unset(self::$fpPtr[$handle], self::$popen[$handle]);
        StreamPathJitHelper::clear($handle);

        return 0 === (int) $ffi->fclose($ffi->cast('void*', $ptr));
    }

    public static function feof(int $handle): bool
    {
        if (!isset(self::$fpPtr[$handle])) {
            return true;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return true;
        }

        return 0 !== (int) $ffi->feof($ffi->cast('void*', self::$fpPtr[$handle]));
    }

    public static function fflush(int $handle): bool
    {
        if (!isset(self::$fpPtr[$handle])) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return 0 === (int) $ffi->fflush($ffi->cast('void*', self::$fpPtr[$handle]));
    }

    public static function pclose(int $handle): int
    {
        if (!isset(self::$fpPtr[$handle])) {
            return 0;
        }
        if (!isset(self::$popen[$handle])) {
            // php-src ext/standard/exec.c — non-popen FILE*: fclose + return 0 (#13305).
            self::fclose($handle);

            return 0;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $ptr = self::$fpPtr[$handle];
        unset(self::$fpPtr[$handle], self::$popen[$handle]);
        StreamPathJitHelper::clear($handle);

        return (int) $ffi->pclose($ffi->cast('void*', $ptr));
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$fpPtr = [];
        self::$popen = [];
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
int fclose(FILE *stream);
int feof(FILE *stream);
int fflush(FILE *stream);
int pclose(FILE *stream);
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
