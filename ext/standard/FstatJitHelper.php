<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * fstat() JIT/AOT helper — SSOT {@see VmFs::fstat()} / {@see VmStreamFstat} (#10460).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(fstat)
 */
final class FstatJitHelper
{
    /** @return HashTable|null null when fstat fails */
    public static function fstatArgv(int $handle): ?HashTable
    {
        $info = VmFs::fstat($handle);
        if (false === $info) {
            return null;
        }

        return $info;
    }

    /**
     * fstat(2) on a libc fileno — thin AOT StreamIo FILE* table (#33359).
     *
     * NestedJIT {@see fstatArgv} cannot see {@see JitStreamIoKernel} handles; peers
     * force ftell/fflush via fileno. Linux uses /proc/self/fd/N ({@see VmStatPure::fstatFd}).
     *
     * @return HashTable|null null when fstat fails
     */
    public static function fstatFdArgv(int $fd): ?HashTable
    {
        $raw = VmStatNative::fstatFd($fd);
        if (false === $raw) {
            return null;
        }

        return self::phpStatArrayToHashTable($raw);
    }

    /**
     * @param array<int|string, int> $stat
     */
    private static function phpStatArrayToHashTable(array $stat): HashTable
    {
        $keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
        $ht = new HashTable();
        $values = [];
        foreach ($keys as $i => $key) {
            $values[$i] = (int) ($stat[$key] ?? $stat[$i] ?? 0);
        }
        foreach ($values as $i => $val) {
            $indexed = new Variable();
            $indexed->int($val);
            $ht->updateIndex($i, $indexed);
        }
        foreach ($keys as $i => $key) {
            $named = new Variable();
            $named->int($values[$i]);
            $ht->add($key, $named);
        }

        return $ht;
    }
}
