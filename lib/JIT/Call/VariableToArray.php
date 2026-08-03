<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Variable::toArray() for nested php-in-PHP JIT helpers (#12910, #26977).
 *
 * Accepts TYPE_VALUE boxes and already-typed TYPE_HASHTABLE receivers.
 */
final class VariableToArray implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('toArray() requires a Variable receiver');
        }
        $receiver = $args[0];
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return HashTableHelper::loadHashtablePointer($context, $receiver);
        }
        $llvmType = $context->getStringFromType($receiver->value->typeOf());
        if ('__hashtable__*' === $llvmType) {
            return Variable::KIND_VALUE === $receiver->kind
                ? $receiver->value
                : $context->builder->load($receiver->value);
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $receiver);

        return $context->builder->call($context->lookupFunction('__value__readHashtable'), $ptr);
    }
}
