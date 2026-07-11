<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rmdir() for compiled JIT/AOT modules (#15481, php-in-PHP).
 *
 * SSOT: {@see VmFs::rmdir()}
 * php-src: ext/standard/filestat.c — php_rmdir
 */
final class RmdirJitHelper
{
    public static function invokeArgv(string $path): bool
    {
        $ok = VmFs::rmdir($path);
        if (!$ok) {
            if (VmStatPath::isDir($path) && VmFs::isDirNonempty($path)) {
                TriggerErrorJitHelper::warning(\sprintf('rmdir(%s): Directory not empty', $path));
            } else {
                TriggerErrorJitHelper::warning(\sprintf('rmdir(%s): No such file or directory', $path));
            }
        }

        return $ok;
    }
}
