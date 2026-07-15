<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename() for compiled JIT/AOT modules (#15533, php-in-PHP).
 *
 * SSOT: {@see VmFs::rename()}
 * php-src: ext/standard/filestat.c — php_rename
 */
final class RenameJitHelper
{
    public static function invokeArgv(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            $ok = false;
        } elseif (null !== VmFsPhpWrapper::renameWarningMessage($from, $to)) {
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

        return $ok;
    }
}
