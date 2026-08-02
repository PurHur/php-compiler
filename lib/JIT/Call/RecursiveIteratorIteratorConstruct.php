<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\RecursiveLeavesFlattenRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * RecursiveIteratorIterator::__construct(Traversable $iterator, …) — thin AOT (#26775).
 *
 * Flattens the inner RecursiveArrayIterator `__spl_ht` into LEAVES_ONLY order so foreach
 * can walk a packed table (php-src ext/spl/spl_iterators.c).
 */
final class RecursiveIteratorIteratorConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('RecursiveIteratorIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'RecursiveIteratorIterator::__construct() expects at least 1 argument, 0 given'
            );
        }

        RecursiveLeavesFlattenRuntime::ensureLinked($context);
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHtVar = $context->type->object->splBackingHashtable($inner);
        $srcHt = $context->helper->loadValue($srcHtVar);
        $flatHt = $context->builder->call(
            $context->lookupFunction(RecursiveLeavesFlattenRuntime::ABI),
            $srcHt
        );
        $flatVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $flatHt
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'RecursiveIteratorIterator',
            '__spl_ht'
        );
        $context->type->object->propertyStore($slot, $flatVar, Variable::TYPE_HASHTABLE);

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
            'RecursiveIteratorIterator::__construct() expects an object, got '
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
