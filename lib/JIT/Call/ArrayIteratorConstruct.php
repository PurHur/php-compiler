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
 * ArrayIterator::__construct(?array $array = [], int $flags = 0) — thin AOT (#26783).
 *
 * Stores a packed hashtable copy in `__spl_ht` so foreach can walk a real table
 * (php-src ext/spl/spl_array.c — spl_array_object_new_ex / set_array).
 */
final class ArrayIteratorConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('ArrayIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            return self::voidResult($context);
        }

        $receiver = self::objectReceiver($context, $args[0]);
        $objPtr = $context->helper->loadValue($receiver);
        $objectType = $context->type->object;
        $slot = $objectType->propertySlotFor($objPtr, 'ArrayIterator', '__spl_ht');
        // Fresh packed HT (native array → new table; HT arg → copy via spread into alloc).
        $src = HashTableHelper::coerceToPackedHashtable($context, $args[1]);
        $copy = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto($context, $copy, $src);
        $objectType->propertyStore($slot, $copy, Variable::TYPE_HASHTABLE);

        return self::voidResult($context);
    }

    private static function objectReceiver(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException(
            'ArrayIterator::__construct() receiver must be an object, got '
            .Variable::getStringType($receiver->type)
        );
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
