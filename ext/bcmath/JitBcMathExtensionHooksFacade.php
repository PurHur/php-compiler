<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\JIT\BcMathExtensionHooks;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * bcmath surfaces for lib/JIT Call BcMathNumber* (#36204).
 *
 * php-src: ext/bcmath/bcmath.c — BcMath\Number construct / methods / __toString.
 * Registered from {@see Module::jitInit} so Call files do not import ext/bcmath.
 */
final class JitBcMathExtensionHooksFacade implements BcMathExtensionHooks
{
    public function numberConstruct(Context $context, JITVariable ...$args): Value
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

    public function numberMethod(Context $context, string $method, JITVariable ...$args): array
    {
        $result = JitBcMathNumberMethods::call($context, $method, ...$args);

        return [$result, JitBcMathNumberMethods::takeLastCompileTimeResult()];
    }

    public function numberToString(Context $context, JITVariable ...$args): Value
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
