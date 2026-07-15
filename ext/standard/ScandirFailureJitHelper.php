<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * scandir() failure warnings for JIT/AOT (php-src ext/standard/dir.c; #18418).
 */
final class ScandirFailureJitHelper
{
    public static function emitWarnings(string $path): void
    {
        TriggerErrorJitHelper::warning(
            \sprintf(
                'scandir(%s): Failed to open directory: %s',
                $path,
                VmDirOpenFailure::openDirFailureReason($path)
            )
        );
        TriggerErrorJitHelper::warning(VmDirOpenFailure::scandirFollowupWarning($path));
    }
}
