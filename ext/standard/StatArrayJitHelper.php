<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stat()/lstat() array for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * Return type is `?array` (not {@see \PHPCompiler\VM\HashTable}): NestedJIT maps class HashTable
 * to object ABI and aborts under thin AOT (#20652 peer FsGlobJitHelper; #35656).
 *
 * SSOT: {@see VmFs::statInfo()} (VM); nested JIT leaf uses @\stat/@\lstat like
 * {@see FsGlobJitHelper} @\glob (do not call VmStatCache — re-enters this helper under thin AOT).
 * php-src: ext/standard/filestat.c — php_stat()
 */
final class StatArrayJitHelper
{
    /**
     * @return array<int|string, int>|null null when stat/lstat fails
     */
    public static function statArgv(string $path, int $useLstat): ?array
    {
        if ('' === $path) {
            return null;
        }
        // Leaf is @\stat/@\lstat — avoid VmStatCache/VmStatPure re-entering this helper under thin AOT
        // (peer FsGlobJitHelper #27235 / #35656).
        $raw = 0 !== $useLstat ? @\lstat($path) : @\stat($path);
        if (!\is_array($raw)) {
            return null;
        }

        return self::phpStatArrayNormalize($raw);
    }

    /**
     * @param array<int|string, int> $stat
     *
     * @return array<int|string, int>
     */
    private static function phpStatArrayNormalize(array $stat): array
    {
        $keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
        $out = [];
        foreach ($keys as $i => $key) {
            if (isset($stat[$key])) {
                $val = (int) $stat[$key];
            } elseif (isset($stat[$i])) {
                $val = (int) $stat[$i];
            } else {
                $val = 0;
            }
            $out[$i] = $val;
            $out[$key] = $val;
        }

        return $out;
    }
}
