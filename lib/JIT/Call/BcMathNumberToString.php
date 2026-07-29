<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\bcmath\JitBcMathNumberInit;
use PHPCompiler\ext\bcmath\VmBcMathNumber;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * BcMath\Number::__toString() — JIT/AOT (#24683).
 *
 * php-src: ext/bcmath/bcmath.c — PHP_METHOD(BcMath_Number, __toString)
 * VM SSOT: {@see \PHPCompiler\ext\bcmath\NumberToString}
 */
final class BcMathNumberToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('BcMath\\Number::__toString() requires $this');
        }
        $str = JitBcMathNumberInit::loadValueString($context, $args[0]);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );

        return $slot;
    }
}
