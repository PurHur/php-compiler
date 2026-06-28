<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Variable::toArray() for nested php-in-PHP JIT helpers (#12910). */
final class VariableToArray implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('toArray() requires a Variable receiver');
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $args[0]);

        return $context->builder->call($context->lookupFunction('__value__readHashtable'), $ptr);
    }
}
