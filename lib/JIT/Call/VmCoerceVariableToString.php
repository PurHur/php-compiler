<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * VM::coerceVariableToString() for nested php-in-PHP JIT helpers (#13137).
 */
final class VmCoerceVariableToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $var = $args[1] ?? null;
        if (null === $var) {
            throw new \LogicException('coerceVariableToString() requires a Variable operand');
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $var);

        return $context->builder->call($context->lookupFunction('__value__readString'), $ptr);
    }
}
