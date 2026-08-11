<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environ mirror for compiled JIT/AOT embed modules (#18984, #30225, php-in-PHP).
 *
 * Init-safe /proc/self/environ mirror via {@see VmEnvEnvironNative::mirrorIntoNativeHashtable()}.
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
