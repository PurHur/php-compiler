<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\standard\DirHandleJitHelper;

/**
 * dir() entry list for JIT/AOT NestedJIT (#30757, #32027).
 *
 * Uses only {@see DirHandleJitHelper} — do not call {@see VmDir} (peer DirectoryIteratorSnapshot).
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
        $handle = DirHandleJitHelper::opendirArgv($path);
        if ($handle < 0) {
            return [];
        }
        $out = [];
        while (true) {
            $name = DirHandleJitHelper::readdirArgv($handle);
            if (null === $name) {
                break;
            }
            $out[] = $name;
        }
        DirHandleJitHelper::closedirArgv($handle);

        return $out;
    }
}
