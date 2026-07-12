<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ArrayPadRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::padCopy() for nested php-in-PHP JIT helpers (#14601). */
final class HashTablePadCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('padCopy() requires HashTable receiver, length, and value');
        }

        $htVar = $args[0];
        if (Variable::TYPE_HASHTABLE !== $htVar->type) {
            $htPtr = HashTableNestedReceiver::hashtableFromReceiver($context, $htVar);
            $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $htPtr);
        }

        return ArrayPadRuntime::pad($context, $htVar, self::lengthAsI64($context, $args[1]), $args[2]);
    }

    private static function lengthAsI64(Context $context, Variable $length): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (Variable::TYPE_INTEGER === $length->type && Variable::KIND_LITERAL === $length->kind) {
            return $i64->constInt((int) $length->literal, false);
        }
        if (Variable::TYPE_VALUE === $length->type) {
            $ptr = JitValueBox::valuePtrFromVariable($context, $length);
            $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);

            return JitNestedHelperCoerce::scalarToI64($context, $long, $context->getTypeFromString('int64'));
        }

        return JitNestedHelperCoerce::scalarToI64(
            $context,
            $context->helper->loadValue($length),
            $context->getTypeFromString('int32')
        );
    }
}
