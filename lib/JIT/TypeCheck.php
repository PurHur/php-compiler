<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;

/**
 * Strict scalar type checks for typed JIT call arguments (issues #156, #1229).
 *
 * Weak coercion is handled in {@see Call\Native::compileArg} when lowering to native LLVM types.
 */
final class TypeCheck
{
    public static function enforceParameter(
        Context $context,
        Variable $var,
        int $vmConstraint,
        bool $strict
    ): void {
        if (!$strict) {
            return;
        }
        $expected = Variable::fromVMVariable($vmConstraint);
        if ($var->type === $expected) {
            return;
        }
        if (
            Variable::TYPE_HASHTABLE === $expected
            && 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            self::enforceExactValueBox($context, $var, $expected);

            return;
        }
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function enforceExactValueBox(Context $context, Variable $var, int $expected): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'strict_type_ok');
        $failBlock = BasicBlockHelper::append($context, 'strict_type_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($expected, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
