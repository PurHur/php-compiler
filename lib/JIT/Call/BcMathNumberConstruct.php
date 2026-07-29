<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\bcmath\JitBcMathNumberInit;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * BcMath\Number::__construct(string|int $num) — JIT/AOT (#24683, #7220).
 *
 * php-src: ext/bcmath/bcmath.c — PHP_METHOD(BcMath_Number, __construct)
 * VM SSOT: {@see \PHPCompiler\ext\bcmath\NumberConstruct}
 */
final class BcMathNumberConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('BcMath\\Number::__construct() requires $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError('BcMath\\Number::__construct() expects exactly 1 argument, 0 given');
        }
        JitBcMathNumberInit::initFromArg($context, $args[0], $args[1]);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
