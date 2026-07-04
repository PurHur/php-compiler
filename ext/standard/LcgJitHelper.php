<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for lcg_value() (#3295).
 *
 * php-src: ext/random/random.c php_combined_lcg()
 */
final class LcgJitHelper
{
    public static function value(): float
    {
        return VmCombinedLcg::value();
    }
}
