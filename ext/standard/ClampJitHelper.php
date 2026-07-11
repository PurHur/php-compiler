<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * clamp() for compiled JIT/AOT modules (php-in-PHP).
 *
 * SSOT: {@see VmMath::clamp()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(clamp), php_math_clamp
 */
final class ClampJitHelper
{
    public static function clampArgv(Variable $value, Variable $min, Variable $max): Variable
    {
        $out = new Variable();
        VmMath::clamp($value, $min, $max, $out);

        return $out;
    }
}
