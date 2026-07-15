<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\SpaceshipCompareJit;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ObjectEntry::compareSpaceship() for nested php-in-PHP JIT helpers (#19048).
 *
 * Routes to {@see __object__compareSpaceship} so CompareJitHelper nested compile
 * does not recurse through unresolved instance-method lowering.
 */
final class ObjectCompareSpaceship implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('compareSpaceship() requires receiver and other object');
        }
        SpaceshipCompareJit::declareAbiFunctions($context);
        $left = ObjectNestedReceiver::objectFromReceiver($context, $args[0]);
        $right = ObjectNestedReceiver::objectFromReceiver($context, $args[1]);

        return $context->builder->call(
            $context->lookupFunction('__object__compareSpaceship'),
            $left,
            $right
        );
    }
}
