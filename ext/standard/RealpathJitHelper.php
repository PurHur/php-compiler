<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * realpath() for compiled JIT/AOT modules (#15323, php-in-PHP).
 *
 * SSOT: {@see VmString::realpath()}
 * php-src: ext/standard/basic_functions.c — php_realpath
 */
final class RealpathJitHelper
{
    public static function resolveArgv(string $path): ?string
    {
        $resolved = VmString::realpath($path);
        if (false === $resolved) {
            return null;
        }

        return $resolved;
    }
}
