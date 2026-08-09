<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * random_bytes() for compiled JIT/AOT modules (#9149, #21186, #29531, php-in-PHP).
 *
 * Leaf is `@random_bytes` → NestedJIT whitelist {@see random_bytes} →
 * {@see JitRandomBytes::generate} → {@see JitRandomBytesKernel} /dev/urandom open/read
 * (no kernel Internal; gethostname #29364 / putenv #29334 shape).
 * VM SSOT remains {@see VmRandomPure} / {@see VmString::randomBytes}.
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class RandomBytesJitHelper
{
    public static function randomBytes(int $length): string
    {
        return \random_bytes($length);
    }
}
