<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environ mirror for compiled JIT/AOT embed modules (#18984, php-in-PHP).
 *
 * Init-safe libc environ walk via {@see VmEnvEnvironNative::mirrorIntoNativeHashtable()} (#19157).
 * SSOT: {@see \PHPCompiler\Web\Superglobals::applyProcessEnvironMirror()}
 * php-src: sapi/cli/php_cli.c — copy environ into $_SERVER on CLI startup
 */
final class EnvironMirrorNativeJitHelper
{
    public static function mirrorProcessEnvironNative(int $destPtr): void
    {
        VmEnvEnvironNative::mirrorIntoNativeHashtable($destPtr);
    }
}
