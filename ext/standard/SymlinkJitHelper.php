<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * symlink() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmFs::symlink()}
 * Thin AOT user-script path uses libc symlink(2) via {@see \PHPCompiler\JIT\Builtin\StringSymlink}
 * (#33416) — this helper remains for VM / NestedJIT consumers of VmFs.
 * php-src: ext/standard/link.c — php_symlink
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
