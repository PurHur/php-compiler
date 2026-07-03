<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * symlink() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmFs::symlink()}
 * php-src: ext/standard/filestat.c — php_symlink
 */
final class SymlinkJitHelper
{
    public static function invokeArgv(string $target, string $link): bool
    {
        $ok = VmFs::symlink($target, $link);
        if (!$ok) {
            TriggerErrorJitHelper::warning('symlink(): No such file or directory');
        }

        return $ok;
    }
}
