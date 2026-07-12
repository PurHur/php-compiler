<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * unlink() for compiled JIT/AOT modules (#15471, php-in-PHP).
 *
 * SSOT: {@see VmFs::unlink()}
 * php-src: ext/standard/filestat.c — php_unlink
 */
final class UnlinkJitHelper
{
    public static function invokeArgv(string $path): bool
    {
        $ok = VmFs::unlink($path);
        if (!$ok) {
            $message = VmFsPhpWrapper::isPhpWrapperPath($path)
                ? VmFsPhpWrapper::unlinkWarningMessage()
                : \sprintf('unlink(%s): No such file or directory', $path);
            TriggerErrorJitHelper::warning($message);
        }

        return $ok;
    }
}
