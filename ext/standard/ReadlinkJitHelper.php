<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * readlink() for compiled JIT/AOT modules (#15353, php-in-PHP).
 *
 * SSOT: {@see VmFs::readlink()}
 * php-src: ext/standard/filestat.c — php_readlink
 */
final class ReadlinkJitHelper
{
    public static function resolveArgv(string $path): ?string
    {
        $target = VmFs::readlink($path);
        if (false === $target) {
            return null;
        }

        return $target;
    }
}
