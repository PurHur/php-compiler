<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM touch() without embedding utime into every call site (#12145, pairs {@see VmFsTouchNative}).
 *
 * Bootstrap / Zend-VM path: host \\touch() for mtime/atime when safe.
 * AOT NestedJIT path: \\touch() re-enters __compiler_touch — use
 * {@see VmFsTouchLibcThinAbi} (or open-append for “now”) instead (#28995).
 *
 * Existence probes must not write {@see VmStatCache}: a positive hit from the probe
 * would make the first post-touch filemtime()/stat() return “now” instead of the
 * utime timestamps (#28995). Host \\stat() during the probe still fills Zend’s
 * BG(CurrentStatFile); clear that path after a successful host touch so the next
 * uncached VmStatNative read sees the new times — without clearing VmStatCache
 * positive entries (php-src keeps those until clearstatcache, #25853).
 *
 * php-src: ext/standard/filestat.c — php_touch
 */
final class VmFsTouchPure
{
    private static bool $reentrant = false;

    public static function available(): bool
    {
        return VmFsOpenNative::available() || VmFsTouchLibcThinAbi::available();
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }

        if (self::$reentrant) {
            return self::touchWithoutPhpTouch($path, $mtime, $atime);
        }

        // Uncached exists — do not use VmStatPath::exists() / VmStatCache (#28995).
        if (false === VmStatNative::stat($path)) {
            $handle = VmFsOpenNative::open($path, 'c');
            if (false === $handle) {
                return false;
            }
            if (!VmFs::fclose($handle)) {
                return false;
            }
        }

        if (null === $mtime && null === $atime) {
            // Prefer open-append so AOT helpers do not need \\touch (recursion risk).
            $handle = VmFsOpenNative::open($path, 'a');
            if (false !== $handle) {
                return VmFs::fclose($handle);
            }
            if (VmFsTouchLibcThinAbi::utimeNow($path)) {
                return true;
            }
            if (!\function_exists('touch')) {
                return false;
            }
            self::$reentrant = true;
            try {
                $ok = @\touch($path);
            } finally {
                self::$reentrant = false;
            }
            if ($ok) {
                self::clearHostStatCache($path);
            }

            return $ok;
        }

        // php-src filestat.c — omitted $atime uses $mtime (2-arg form).
        $now = self::now();
        $mtimeEff = $mtime ?? $now;
        $atimeEff = $atime ?? $mtimeEff;

        // Under NestedJIT AOT, \\touch() re-enters this method — prefer libc utime first
        // when already compiled into the helper (#28995).
        if (VmFsTouchLibcThinAbi::available()) {
            if (VmFsTouchLibcThinAbi::utime($path, $atimeEff, $mtimeEff)) {
                self::clearHostStatCache($path);

                return true;
            }
        }

        if (\function_exists('touch')) {
            self::$reentrant = true;
            try {
                $ok = @\touch($path, $mtimeEff, $atimeEff);
            } finally {
                self::$reentrant = false;
            }
            if ($ok) {
                self::clearHostStatCache($path);

                return true;
            }
        }

        return self::setTimesWithoutPhpTouch($path, $mtimeEff, $atimeEff);
    }

    /**
     * Nested __compiler_touch body — never call \\touch().
     */
    private static function touchWithoutPhpTouch(string $path, ?int $mtime, ?int $atime): bool
    {
        if (false === VmStatNative::stat($path)) {
            $handle = VmFsOpenNative::open($path, 'c');
            if (false === $handle) {
                return false;
            }
            if (!VmFs::fclose($handle)) {
                return false;
            }
        }

        if (null === $mtime && null === $atime) {
            $handle = VmFsOpenNative::open($path, 'a');
            if (false !== $handle) {
                return VmFs::fclose($handle);
            }

            return VmFsTouchLibcThinAbi::utimeNow($path);
        }

        $now = self::now();
        $mtimeEff = $mtime ?? $now;
        $atimeEff = $atime ?? $mtimeEff;

        return self::setTimesWithoutPhpTouch($path, $mtimeEff, $atimeEff);
    }

    private static function setTimesWithoutPhpTouch(string $path, int $mtime, int $atime): bool
    {
        if (VmFsTouchLibcThinAbi::utime($path, $atime, $mtime)) {
            self::clearHostStatCache($path);

            return true;
        }

        // Last-resort host bootstrap when FFI is disabled (#12145).
        if (!\function_exists('exec')) {
            return false;
        }
        $q = \escapeshellarg($path);
        $cmd = \sprintf('touch -a -d @%d %s && touch -m -d @%d %s', $atime, $q, $mtime, $q);
        $output = [];
        $code = 1;
        @\exec($cmd, $output, $code);
        if (0 === $code) {
            self::clearHostStatCache($path);

            return true;
        }

        return false;
    }

    private static function now(): int
    {
        if (\function_exists('time')) {
            return (int) \time();
        }

        return 0;
    }

    /** Flush host PHP BG stat cache for $path only — leave VmStatCache alone (#25853). */
    private static function clearHostStatCache(string $path): void
    {
        if (\function_exists('clearstatcache')) {
            @\clearstatcache(true, $path);
        }
    }
}
