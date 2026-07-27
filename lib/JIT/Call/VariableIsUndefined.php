<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Variable::isUndefined() for nested php-in-PHP JIT helpers (#23974).
 *
 * VM SSOT: {@see \PHPCompiler\VM\Variable::isUndefined()}
 */
final class VariableIsUndefined implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('isUndefined() requires a Variable receiver');
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($ptr, $valueMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $undef = $i8->constInt(VmVariable::TYPE_UNDEFINED, false);

        return $context->builder->icmp(Builder::INT_EQ, $typeByte, $undef);
    }
}
