<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM touch() without libc utime/stat/open FFI (#12145, pairs {@see VmFsTouchNative}).
 *
 * Bootstrap path: VmFsOpenNative exclusive create + host touch() for mtime/atime.
 *
 * php-src: ext/standard/filestat.c — php_touch
 *
 * Existence probes must not write {@see VmStatCache}: a positive hit from the probe
 * would make the first post-touch filemtime()/stat() return “now” instead of the
 * utime timestamps (#28995). Host \\stat() during the probe still fills Zend’s
 * BG(CurrentStatFile); clear that path after a successful host touch so the next
 * uncached VmStatNative read sees the new times — without clearing VmStatCache
 * positive entries (php-src keeps those until clearstatcache, #25853).
 */
final class VmFsTouchPure
{
    public static function available(): bool
    {
        return VmFsOpenNative::available();
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
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
            if (\function_exists('touch')) {
                $ok = @\touch($path);
                if ($ok) {
                    self::clearHostStatCache($path);
                }

                return $ok;
            }
            $handle = VmFsOpenNative::open($path, 'a');
            if (false === $handle) {
                return false;
            }

            return VmFs::fclose($handle);
        }

        if (!\function_exists('touch')) {
            return false;
        }

        // php-src: omitted atime uses mtime (2-arg form). Passing null is Z_PARAM_LONG_OR_NULL unset.
        $ok = @\touch($path, $mtime, $atime);
        if ($ok) {
            self::clearHostStatCache($path);
        }

        return $ok;
    }

    /** Flush host PHP BG stat cache for $path only — leave VmStatCache alone (#25853). */
    private static function clearHostStatCache(string $path): void
    {
        if (\function_exists('clearstatcache')) {
            @\clearstatcache(true, $path);
        }
    }
}
