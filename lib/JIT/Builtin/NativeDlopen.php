<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

/**
 * Shared lazy dlopen helper for JIT MCJIT symbol preloading (#8727).
 *
 * Single SSOT for libdl FFI — compression JIT builtins preload host .so
 * so MCJIT resolves symbols at JIT compile time.
 */
final class NativeDlopen
{
    /** RTLD_LAZY (0x1) | RTLD_GLOBAL (0x100) — match prior compression preload flags. */
    private const RTLD_LAZY_GLOBAL = 0x101;

    private static ?\FFI $dl = null;

    private static bool $dlUnavailable = false;

    /** @var null|callable(string, int): ?\FFI\CData */
    private static $dlopenOverride = null;

    /**
     * Preload first resolvable library from candidates (paths or sonames).
     *
     * @param list<string> $candidates
     */
    public static function preloadLibraries(array $candidates): bool
    {
        if ([] === $candidates || self::shouldSkipPreload()) {
            return false;
        }
        $dl = self::dl();
        if (null === $dl) {
            return false;
        }
        foreach ($candidates as $path) {
            if ('' === $path) {
                continue;
            }
            if (null !== self::dlopen($dl, $path, self::RTLD_LAZY_GLOBAL)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldSkipPreload(): bool
    {
        if (!\extension_loaded('FFI')) {
            return true;
        }
        $selfHost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfHost || 'true' === strtolower((string) $selfHost)) {
            return true;
        }

        return false;
    }

    /** Test seam: replace dlopen (null restores default). */
    public static function setDlopenOverride(?callable $callback): void
    {
        self::$dlopenOverride = $callback;
    }

    /** Test seam: reset cached libdl handle and overrides. */
    public static function resetForTesting(): void
    {
        self::$dl = null;
        self::$dlUnavailable = false;
        self::$dlopenOverride = null;
    }

    private static function dl(): ?\FFI
    {
        if (self::$dlUnavailable) {
            return null;
        }
        if (null !== self::$dl) {
            return self::$dl;
        }
        try {
            self::$dl = \FFI::cdef(
                'void *dlopen(const char *filename, int flags); void *dlsym(void *handle, const char *symbol);',
                'libdl.so.2'
            );

            return self::$dl;
        } catch (\Throwable) {
            self::$dlUnavailable = true;

            return null;
        }
    }

    /**
     * @return \FFI\CData|null opaque handle
     */
    private static function dlopen(\FFI $dl, string $path, int $flags): ?\FFI\CData
    {
        if (null !== self::$dlopenOverride) {
            return (self::$dlopenOverride)($path, $flags);
        }
        try {
            $handle = $dl->dlopen($path, $flags);

            return null !== $handle ? $handle : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
