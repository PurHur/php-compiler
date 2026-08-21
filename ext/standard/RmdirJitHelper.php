<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rmdir() for compiled JIT/AOT modules (#15481, php-in-PHP).
 *
 * Thin AOT user-script path uses {@see \PHPCompiler\JIT\Builtin\RmdirLibcRuntime}
 * (libc rmdir(2)) — NestedJIT helpers cannot call host \\rmdir (#33403).
 * This helper remains the VM / embed SSOT via {@see VmFs::rmdir()}.
 *
 * php-src: ext/standard/filestat.c — php_rmdir
 */
final class RmdirJitHelper
{
    public static function invokeArgv(string $path): bool
    {
        $ok = VmFs::rmdir($path);
        if (!$ok) {
            if (VmStatPath::isDir($path) && VmFs::isDirNonempty($path)) {
                TriggerErrorJitHelper::warning('rmdir('.$path.'): Directory not empty');
            } else {
                TriggerErrorJitHelper::warning('rmdir('.$path.'): No such file or directory');
            }
        }

        return $ok;
    }
}
