<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * dir() entry list for JIT/AOT NestedJIT (#30757, #32027, #33009, #33263).
 *
 * NestedJIT `\scandir()` directly (same leaf as user-script scandir). Do not call
 * {@see FsGlobJitHelper::scandirArgv} from this helper (empty listing under NestedJIT
 * indirection). Do not call {@see DirHandleJitHelper} / {@see VmDir} (#33009).
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
        $entries = \scandir($path, \SCANDIR_SORT_NONE);
        if (!\is_array($entries)) {
            return [];
        }

        return \array_values($entries);
    }
}
