<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for spaceship (<=>) on objects and boxed values (#4661, #5185).
 *
 * LLVM bodies from {@see SpaceshipCompareJit}; uses phpc_object_prop_count from phpc_gc.c.
 */
final class SpaceshipRuntime
{
    public static function ensureLinked(Context $context): void
    {
        GcCollectCyclesRuntime::ensureLinked($context);
        self::implement($context);
    }

    public static function callValueSpaceship(Context $context, Value $leftPtr, Value $rightPtr): Value
    {
        $fn = $context->lookupFunction('__value__spaceship');
        $params = $fn->typeOf()->getElementType()->getParameters();

        return $context->builder->call(
            $fn,
            $context->builder->pointerCast($leftPtr, $params[0]),
            $context->builder->pointerCast($rightPtr, $params[1])
        );
    }

    public static function callObjectCompareSpaceship(Context $context, Value $leftObj, Value $rightObj): Value
    {
        $fn = $context->lookupFunction('__object__compareSpaceship');
        $params = $fn->typeOf()->getElementType()->getParameters();

        return $context->builder->call(
            $fn,
            $context->builder->pointerCast($leftObj, $params[0]),
            $context->builder->pointerCast($rightObj, $params[1])
        );
    }

    public static function implement(Context $context): void
    {
        $resume = null;
        try {
            $resume = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        SpaceshipCompareJit::implement($context);

        if (null !== $resume) {
            $context->builder->positionAtEnd($resume);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
