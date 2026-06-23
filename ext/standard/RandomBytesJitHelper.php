<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * random_bytes() CSPRNG for compiled JIT/AOT modules (#9149, php-in-PHP).
 *
 * SSOT: {@see VmRandomPure::randomBytes()}
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class RandomBytesJitHelper
{
    public static function randomBytes(int $length): string
    {
        return VmRandomPure::randomBytes($length);
    }
}
