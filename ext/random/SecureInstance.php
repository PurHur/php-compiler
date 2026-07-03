<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmRandom;
use PHPCompiler\ext\standard\VmString;

/** Random\\Engine\\Secure (php-src ext/random/engine_secure.c; #11550). */
final class SecureInstance
{
    public function generate(): int
    {
        $bytes = VmString::randomBytes(\PHP_INT_SIZE);
        $unpacked = \unpack('P', $bytes);

        return (int) ($unpacked[1] ?? 0);
    }

    public function range(int $min, int $max): int
    {
        return VmRandom::randomInt($min, $max);
    }
}
