<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * Thin directory snapshot for AOT DirectoryIterator (#27289, #33009, #33263).
 *
 * NestedJIT `\scandir()` directly (same whitelist leaf as user-script scandir — works under
 * thin AOT). Do not call {@see \PHPCompiler\ext\standard\FsGlobJitHelper::scandirArgv} from this
 * helper — NestedJIT of that indirection returned [] after #33009. Do not call
 * {@see \PHPCompiler\ext\standard\DirHandleJitHelper} / {@see \PHPCompiler\ext\standard\VmDir}
 * — NestedJIT DirHandle→VmDirPure listing is empty (#33009).
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
        // Call scandir() directly so NestedJIT hits the same whitelist leaf as user-script
        // scandir() (which works under thin AOT). FsGlobJitHelper::scandirArgv nested from
        // this helper was returning [] (#33263 / regression after #33009).
        // SCANDIR_SORT_NONE — DirectoryIterator matches opendir/readdir order (#14859).
        $entries = \scandir($path, \SCANDIR_SORT_NONE);
        if (!\is_array($entries)) {
            return [];
        }
        $entries = \array_values($entries);
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
