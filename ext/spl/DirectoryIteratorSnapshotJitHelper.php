<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\DirHandleJitHelper;

/**
 * Thin directory snapshot for AOT DirectoryIterator (#27289).
 *
 * Uses only {@see DirHandleJitHelper} (already NestedJIT'd by StringDir) — do not call
 * {@see \PHPCompiler\ext\standard\VmDir} here (pulls a huge NestedJIT closure / OOM).
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
        $handle = DirHandleJitHelper::opendirArgv($path);
        if ($handle < 0) {
            return [];
        }
        $skipDots = 0 !== ($flags & self::FLAG_SKIP_DOTS);
        $out = [];
        while (true) {
            $name = DirHandleJitHelper::readdirArgv($handle);
            if (null === $name) {
                break;
            }
            if ($skipDots && ('.' === $name || '..' === $name)) {
                continue;
            }
            $out[] = $name;
        }
        DirHandleJitHelper::closedirArgv($handle);

        return $out;
    }
}
