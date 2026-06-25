<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * stat()/lstat() array for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * SSOT: {@see VmFs::statInfo()} (VM); this helper uses {@see VmStatCache} only so
 * nested JIT standalone compile does not pull all of VmFs.
 * php-src: ext/standard/filestat.c — php_stat()
 */
final class StatArrayJitHelper
{
    /** @return HashTable|null null when stat/lstat fails */
    public static function statArgv(string $path, int $useLstat): ?HashTable
    {
        if ('' === $path) {
            return null;
        }
        $raw = 0 !== $useLstat ? VmStatCache::lstat($path) : VmStatCache::stat($path);
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
            if (isset($stat[$key])) {
                $values[$i] = (int) $stat[$key];
            } elseif (isset($stat[$i])) {
                $values[$i] = (int) $stat[$i];
            } else {
                $values[$i] = 0;
            }
        }
        foreach ($values as $val) {
            $ht->append(self::intVariable($val));
        }
        foreach ($keys as $i => $key) {
            $ht->add($key, self::intVariable($values[$i]));
        }

        return $ht;
    }

    private static function intVariable(int $value): Variable
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }
}
