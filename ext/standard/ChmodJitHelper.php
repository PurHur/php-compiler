<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chmod() for compiled JIT/AOT modules (#15458, php-in-PHP).
 *
 * SSOT: {@see VmFs::chmod()}
 * php-src: ext/standard/filestat.c — php_chmod
 */
final class ChmodJitHelper
{
    public static function invokeArgv(string $path, int $mode): bool
    {
        $ok = VmFs::chmod($path, $mode);
        if (!$ok) {
            TriggerErrorJitHelper::warning('chmod(): No such file or directory');
        }

        return $ok;
    }
}
