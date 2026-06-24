<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stat()/lstat() without libc stat(2) FFI — host stat bootstrap path (#8903, #1492).
 *
 * VM under Zend PHP uses native \\stat()/\\lstat() when FFI is disabled; layout matches
 * {@see VmStatNative} / {@see JitStat} zend_stat field order.
 *
 * php-src: Zend/zend_stat.c — php_stat / php_lstat array keys
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
        return \function_exists('stat');
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
        if (!self::available()) {
            return false;
        }

        $raw = $lstat ? @\lstat($path) : @\stat($path);
        if (false === $raw || !\is_array($raw)) {
            return false;
        }

        return self::normalize($raw);
    }
}
