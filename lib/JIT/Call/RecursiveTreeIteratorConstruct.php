<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\RecursiveTreeIteratorBuildRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Value;

/**
 * RecursiveTreeIterator::__construct — thin AOT tree strings into `__spl_ht` (#27584).
 *
 * php-src: ext/spl/spl_iterators.c — RecursiveTreeIterator
 */
final class RecursiveTreeIteratorConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('RecursiveTreeIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'RecursiveTreeIterator::__construct() expects at least 1 argument, 0 given'
            );
        }
        RecursiveTreeIteratorBuildRuntime::ensureLinked($context);
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHt = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        $tree = $context->builder->call(
            $context->lookupFunction(RecursiveTreeIteratorBuildRuntime::ABI),
            $srcHt
        );
        $treeVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $tree
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'RecursiveTreeIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $treeVar, Variable::TYPE_HASHTABLE);

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
            'RecursiveTreeIterator::__construct() expects an object, got '
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
