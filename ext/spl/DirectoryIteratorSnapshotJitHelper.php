<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\FsGlobJitHelper;

/**
 * Thin directory snapshot for AOT DirectoryIterator (#27289, #33009).
 *
 * Uses {@see FsGlobJitHelper::scandirArgv} (NestedJIT whitelist → libc scandir vec, peer #29986).
 * Do not call {@see \PHPCompiler\ext\standard\DirHandleJitHelper} / {@see \PHPCompiler\ext\standard\VmDir}
 * here — NestedJIT DirHandle→VmDirPure listing returns empty under thin AOT (#33009).
 *
 * Return type is `array` (not HashTable): NestedJIT maps class HashTable to object ABI
 * (peer HashAlgosJitHelper #20652). `array` → `__hashtable__*`.
 *
 * php-src: ext/spl/spl_directory.c — spl_filesystem_dir_open
 */
final class DirectoryIteratorSnapshotJitHelper
{
    private const FLAG_SKIP_DOTS = 4096;

    /**
     * @return list<string>
     */
    public static function entriesArgv(string $path, int $flags): array
    {
        // SCANDIR_SORT_NONE — DirectoryIterator matches opendir/readdir order (#14859).
        $entries = FsGlobJitHelper::scandirArgv($path, \SCANDIR_SORT_NONE);
        if (null === $entries) {
            return [];
        }
        if (0 === ($flags & self::FLAG_SKIP_DOTS)) {
            return $entries;
        }
        $out = [];
        foreach ($entries as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $out[] = $name;
        }

        return $out;
    }
}
