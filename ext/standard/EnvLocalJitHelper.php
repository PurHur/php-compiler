<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * putenv()/getenv() local overlay for compiled JIT/AOT modules (#9814, php-in-PHP).
 *
 * SSOT: {@see GetenvJitHelper} static overlay storage.
 * Zero-arg getenv merge uses {@see \PHPCompiler\JIT\Builtin\EnvLocalOverlayTableLlvm} (#12810, #1492).
 * php-src: ext/standard/basic_functions.c — EG(env)
 */
final class EnvLocalJitHelper
{
    public static function lookupOverlay(string $name): string|false
    {
        return GetenvJitHelper::getenv($name, 1);
    }

    public static function registerPutenv(string $assignment): bool
    {
        return GetenvJitHelper::putenv($assignment);
    }
}
