<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

/**
 * random extension constants (php-src ext/random/random.c; #17799).
 */
final class RandomConstants
{
    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'MT_RAND_MT19937' => Mt19937Instance::MT_RAND_MT19937,
            'MT_RAND_PHP' => Mt19937Instance::MT_RAND_PHP,
        ];
    }
}
