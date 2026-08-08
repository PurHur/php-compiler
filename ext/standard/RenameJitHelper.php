<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename() for compiled JIT/AOT modules (#15533, #29090, php-in-PHP).
 *
 * NestedJIT leaf: {@see phpc_rename_kernel} → {@see \PHPCompiler\JIT\Builtin\StringRename}
 * module-local rename(2) (LibcExtern rename row removed). Full {@see VmFs::rename()} /
 * {@code @rename} under NestedJIT is not yet a drop-in — Context only resolves whitelisted
 * kernels before registerModule (#15417); plain rename stays an ExternalMethod stub.
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (bool boxes have no readLong arm and always yield 0; see #20603 / HashEquals i32 ABI).
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
        if (null !== VmFsPhpWrapper::renameWarningMessage($from, $to)) {
            $ok = false;
        } else {
            $ok = \phpc_rename_kernel($from, $to);
        }
        if ($ok) {
            VmStatCache::invalidatePath($from);
            VmStatCache::invalidatePath($to);
        }
        if (!$ok) {
            $wrapperMessage = VmFsPhpWrapper::renameWarningMessage($from, $to);
            TriggerErrorJitHelper::warning(
                null !== $wrapperMessage
                    ? $wrapperMessage
                    : 'rename('.$from.','.$to.'): No such file or directory'
            );
        }

        return $ok ? 1 : 0;
    }
}
