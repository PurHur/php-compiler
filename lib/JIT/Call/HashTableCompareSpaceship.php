<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitSpaceshipCompareKernel;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::compareSpaceship() for nested php-in-PHP JIT helpers (#19048). */
final class HashTableCompareSpaceship implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('compareSpaceship() requires receiver and other hashtable');
        }
        JitSpaceshipCompareKernel::declareAbiFunctions($context);
        $left = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $right = HashTableNestedReceiver::hashtableFromReceiver($context, $args[1]);

        return $context->builder->call(
            $context->lookupFunction('__hashtable__compareSpaceship'),
            $left,
            $right
        );
    }
}
