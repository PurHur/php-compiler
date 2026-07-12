<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * opendir() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmDir::opendir()} + {@see VmFilestatFailure::warnPathOpenDirFailed()} parity with {@see opendir::execute()}.
 * php-src: ext/standard/dir.c — php_opendir
 */
final class OpendirJitHelper
{
    /**
     * @return int handle on success, -1 on failure (maps to bool false in LLVM bridge)
     */
    public static function invokeArgv(string $path): int
    {
        if ('' === $path) {
            return -1;
        }
        $handle = VmDir::opendir($path);
        if (false === $handle) {
            $reason = VmFsPhpWrapper::openDirFailureReason($path);
            TriggerErrorJitHelper::warning(
                \sprintf('opendir(%s): Failed to open directory: %s', $path, $reason)
            );

            return -1;
        }

        return (int) $handle;
    }
}
