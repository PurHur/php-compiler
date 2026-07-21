<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Variable::toString() for nested php-in-PHP JIT helpers (#12910, #21921).
 *
 * Owns a separated {@see __string__*} so NestedJIT strlen/substr see the length
 * field (HashTable::find → toString must not yield length=0 with live bytes).
 */
final class VariableToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('toString() requires a Variable receiver');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'var_to_string_cont');
        $ptr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $raw = $context->builder->call($context->lookupFunction('__value__readString'), $ptr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $raw,
            $strPtrTy->constNull()
        );
        $nullBb = BasicBlockHelper::append($context, 'var_to_string_null');
        $okBb = BasicBlockHelper::append($context, 'var_to_string_ok');
        $doneBb = BasicBlockHelper::append($context, 'var_to_string_done');
        $context->builder->branchIf($isNull, $nullBb, $okBb);

        $context->builder->positionAtEnd($nullBb);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $raw
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($strPtrTy, 'var_to_string_result');
        $phi->addIncoming($empty, $nullBb);
        $phi->addIncoming($owned, $okBb);

        return $phi;
    }
}
