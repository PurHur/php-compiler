<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class IsStringFn implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('is_string() expects at least one argument');
        }
        $arg = $args[count($args) - 1];
        if (null !== $arg->compileTimeString) {
            return $context->constantFromBool(true);
        }
        if (Variable::TYPE_STRING === ($arg->type & ~Variable::IS_REFCOUNTED)) {
            return $context->constantFromBool(true);
        }
        if (Variable::TYPE_VALUE === ($arg->type & ~Variable::IS_REFCOUNTED)) {
            $valPtr = Variable::KIND_VARIABLE === $arg->kind
                ? JitValueBox::pointer($context, $arg->value)
                : $context->helper->loadValue($arg);
            $typeByte = $context->builder->load(
                $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
            );
            $i8 = $context->getTypeFromString('int8');

            return $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            );
        }

        return $context->constantFromBool(false);
    }
}
