<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * dir() entry list for JIT/AOT NestedJIT (#30757, #32027, #33009).
 *
 * Uses {@see FsGlobJitHelper::scandirArgv} — NestedJIT whitelist → libc scandir vec (peer #29986).
 * Do not call {@see DirHandleJitHelper} / {@see VmDir} (empty listing under thin AOT — #33009).
 *
 * @return list<string>
 */
final class DirSnapshotJitHelper
{
    /**
     * @return list<string>
     */
    public static function entriesArgv(string $path): array
    {
        $entries = FsGlobJitHelper::scandirArgv($path, \SCANDIR_SORT_NONE);
        if (null === $entries) {
            return [];
        }

        return $entries;
    }
}
