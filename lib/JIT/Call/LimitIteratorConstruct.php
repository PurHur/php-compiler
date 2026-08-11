<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableSliceLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LimitIterator::__construct — thin AOT snapshot of inner `__spl_ht` window (#26825).
 *
 * php-src: ext/spl/spl_iterators.c — spl_dual_it_construct / LimitIterator
 * Keys are preserved (preserve_keys=1) so foreach matches Zend/VM (#27581).
 *
 * InfiniteIterator inners tile the backing HT for a finite $count (#30273) —
 * a plain slice cannot expand past one cycle of the infinite stream.
 *
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs
 * after __construct (otherwise constructed=0 aborts get_class / HT reads).
 */
final class LimitIteratorConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('LimitIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'LimitIterator::__construct() expects at least 1 argument, 0 given'
            );
        }

        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $srcHtVar = $context->type->object->splBackingHashtable($inner);
        $copy = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto($context, $copy, $srcHtVar);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $offset = isset($args[2])
            ? self::toI64($context, $args[2])
            : $i64->constInt(0, false);
        $count = isset($args[3])
            ? self::toI64($context, $args[3])
            : $i64->constInt(-1, false);
        $hasLength = $context->builder->icmp(
            Builder::INT_SGE,
            $count,
            $i64->constInt(0, false)
        );
        $srcHt = $context->helper->loadValue($copy);

        $infId = $context->type->object->lookup('InfiniteIterator');
        $innerPtr = $context->helper->loadValue($inner);
        $classId = $context->builder->load(
            $context->builder->structGep(
                $innerPtr,
                $context->structFieldMap['__object__']['class_id']
            )
        );
        $isInf = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $classId->typeOf()->constInt($infId, false)
        );
        $wrap = $context->builder->and($isInf, $hasLength);

        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__hashtable__*')
        );
        $wrapBb = BasicBlockHelper::append($context, 'limit_it_wrap');
        $sliceBb = BasicBlockHelper::append($context, 'limit_it_slice');
        $joinBb = BasicBlockHelper::append($context, 'limit_it_join');
        $context->builder->branchIf($wrap, $wrapBb, $sliceBb);

        $context->builder->positionAtEnd($wrapBb);
        $context->builder->store(
            HashTableSliceLlvm::sliceWrapping($context, $srcHt, $offset, $count),
            $resultSlot
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($sliceBb);
        // preserveKeys=true — LimitIterator forwards inner keys (php-src spl_limit_it_key; #27581).
        $context->builder->store(
            HashTableSliceLlvm::slice(
                $context,
                $srcHt,
                $offset,
                $hasLength,
                $count,
                $i1->constInt(1, false)
            ),
            $resultSlot
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $slicedVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->load($resultSlot)
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'LimitIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $slicedVar, Variable::TYPE_HASHTABLE);

        return self::voidResult($context);
    }

    private static function toI64(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->helper->loadValue($arg),
                $context->getTypeFromString('int64')
            );
        }
        if (Variable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            return JitNestedHelperCoerce::scalarToI64(
                $context,
                $context->builder->call(
                    $context->lookupFunction('__value__toLong'),
                    JitValueBox::valuePtrFromVariable($context, $arg)
                ),
                $context->getTypeFromString('int64')
            );
        }

        throw new \LogicException(
            'LimitIterator::__construct() offset/count must be int, got '
            .Variable::getStringType($arg->type)
        );
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
            'LimitIterator::__construct() expects an object, got '
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
