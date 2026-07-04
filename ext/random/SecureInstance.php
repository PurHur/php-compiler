<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmRandom;
use PHPCompiler\ext\standard\VmString;

/** Random\\Engine\\Secure (php-src ext/random/engine_secure.c; #11550). */
final class SecureInstance
{
    public function generate(): string
    {
        return VmString::randomBytes(8);
    }

    public function range(int $min, int $max): int
    {
        return VmRandom::randomInt($min, $max);
    }
}
