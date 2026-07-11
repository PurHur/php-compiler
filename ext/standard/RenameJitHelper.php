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
        $ok = VmFs::rename($from, $to);
        if (!$ok) {
            TriggerErrorJitHelper::warning(
                \sprintf('rename(%s,%s): No such file or directory', $from, $to)
            );
        }

        return $ok;
    }
}
