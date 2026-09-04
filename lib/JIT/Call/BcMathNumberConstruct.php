<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * BcMath\Number::__construct(string|int $num) — JIT/AOT (#24683, #7220).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\bcmath} (#36204). php-src: ext/bcmath/bcmath.c — PHP_METHOD(BcMath_Number, __construct)
 * VM SSOT: {@see \PHPCompiler\ext\bcmath\NumberConstruct}
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class BcMathNumberConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireBcMath()->numberConstruct($context, ...$args);
    }
}
