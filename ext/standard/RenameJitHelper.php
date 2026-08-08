<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename() for compiled JIT/AOT modules (#15533, #29090, #29141, php-in-PHP).
 *
 * Leaf is `@rename` → NestedJIT whitelist {@see rename_} →
 * {@see \PHPCompiler\JIT\Builtin\StringRename::invokeNestedLeaf} (no kernel).
 * User-stream / warning / stat-cache match pre-#29141 helper control flow without
 * pulling {@see VmFs} into the NestedJIT TU (#579 stubs).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/standard/filestat.c — php_rename
 */
final class RenameJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(string $from, string $to): int
    {
        $userOk = VmUserStream::tryRename($from, $to);
        if (null !== $userOk) {
            if ($userOk) {
                VmStatCache::invalidatePath($from);
                VmStatCache::invalidatePath($to);
            } else {
                TriggerErrorJitHelper::warning(
                    'rename('.$from.','.$to.'): No such file or directory'
                );
            }

            return $userOk ? 1 : 0;
        }
        $wrapperMessage = VmFsPhpWrapper::renameWarningMessage($from, $to);
        if (null !== $wrapperMessage) {
            TriggerErrorJitHelper::warning($wrapperMessage);

            return 0;
        }
        $ok = @\rename($from, $to);
        if ($ok) {
            VmStatCache::invalidatePath($from);
            VmStatCache::invalidatePath($to);
        } else {
            TriggerErrorJitHelper::warning(
                'rename('.$from.','.$to.'): No such file or directory'
            );
        }

        return $ok ? 1 : 0;
    }
}
