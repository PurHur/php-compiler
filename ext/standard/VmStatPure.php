<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stat()/lstat()/fstat/realpath without libc stat(2) FFI — SSOT (#8903, #12265, #1492).
 *
 * VM under Zend PHP uses native \\stat()/\\lstat()/\\realpath() on bootstrap paths; layout
 * matches {@see JitStat} zend_stat field order. Linux fstat uses /proc/self/fd/N lstat.
 *
 * php-src: Zend/zend_stat.c — php_stat / php_lstat array keys; ext/standard/filestat.c fstat
 */
final class VmStatPure
{
    /** @var list<string> */
    private const STAT_KEYS = [
        'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size',
        'atime', 'mtime', 'ctime', 'blksize', 'blocks',
    ];

    public static function available(): bool
    {
        return \function_exists('stat')
            || ('Linux' === \PHP_OS_FAMILY && self::procFdStatAvailable());
    }

    private static function procFdStatAvailable(): bool
    {
        return \is_readable('/proc/self/fd');
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function stat(string $path)
    {
        return self::invoke($path, false);
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function lstat(string $path)
    {
        return self::invoke($path, true);
    }

    /**
     * fstat(2) on an open fd via /proc/self/fd/N (Linux) or host lstat fallback.
     *
     * @return array<int|string, int>|false
     */
    public static function fstatFd(int $fd)
    {
        if ($fd < 0) {
            return false;
        }
        if ('Linux' === \PHP_OS_FAMILY && self::procFdStatAvailable()) {
            return self::lstat('/proc/self/fd/'.$fd);
        }

        return false;
    }

    public static function realpath(string $path): string|false
    {
        if ('' === $path) {
            $path = '.';
        }
        if (str_contains($path, "\0")) {
            return false;
        }
        if (\function_exists('realpath')) {
            $out = @\realpath($path);

            return (false === $out || '' === $out) ? false : $out;
        }
        if ('Linux' !== \PHP_OS_FAMILY) {
            return false;
        }

        return self::realpathLinuxNormalized($path);
    }

    /**
     * @param array<int|string, int> $raw
     *
     * @return array<int|string, int>
     */
    public static function normalize(array $raw): array
    {
        $values = [];
        $i = 0;
        foreach (self::STAT_KEYS as $key) {
            $values[] = (int) ($raw[$key] ?? $raw[$i] ?? 0);
            ++$i;
        }
        $out = [];
        foreach ($values as $i => $value) {
            $out[$i] = $value;
        }
        foreach (self::STAT_KEYS as $i => $key) {
            $out[$key] = $values[$i];
        }

        return $out;
    }

    /**
     * @return array<int|string, int>|false
     */
    private static function invoke(string $path, bool $lstat)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (!\function_exists('stat')) {
            return false;
        }

        $raw = $lstat ? @\lstat($path) : @\stat($path);
        if (false === $raw || !\is_array($raw)) {
            return false;
        }

        return self::normalize($raw);
    }

    /**
     * Linux bootstrap without host realpath(3): getcwd + normalizePath (no symlink walk).
     */
    private static function realpathLinuxNormalized(string $path): string|false
    {
        $absolute = '' !== $path && ('/' === $path[0] || '\\' === $path[0]);
        if (!$absolute) {
            $cwd = VmGetcwdNative::resolve();
            if (false === $cwd || '' === $cwd) {
                return false;
            }
            $path = VmString::normalizePath($cwd.'/'.$path);
        } else {
            $path = VmString::normalizePath($path);
        }
        if (!self::pathExistsForRealpath($path)) {
            return false;
        }

        return $path;
    }

    private static function pathExistsForRealpath(string $path): bool
    {
        if ('' === $path) {
            return false;
        }
        if (\function_exists('file_exists')) {
            return @\file_exists($path);
        }

        return false !== self::stat($path);
    }
}
