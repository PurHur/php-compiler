<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableKeyFilterLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * CachingIterator::__construct — thin AOT snapshot into `__spl_ht` (+ FULL_CACHE) (#27421).
 *
 * Copies inner ArrayIterator HT for foreach. With FULL_CACHE (0x100), also stores a
 * key-preserving copy on `__spl_cache` so getCache() matches Zend after a full walk.
 *
 * php-src: ext/spl/spl_iterators.c — spl_caching_it_construct / FULL_CACHE
 */
final class CachingIteratorConstruct implements Call
{
    /** php-src CIT_FULL_CACHE */
    private const FULL_CACHE = 0x00000100;

    public const PROP_CACHE = '__spl_cache';

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('CachingIterator::__construct() called without $this');
        }
        if (!isset($args[1])) {
            throw new \ArgumentCountError(
                'CachingIterator::__construct() expects at least 1 argument, 0 given'
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
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'CachingIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $copy, Variable::TYPE_HASHTABLE);

        $flags = isset($args[2])
            ? self::toI64($context, $args[2])
            : $context->getTypeFromString('int64')->constInt(0, false);
        $i64 = $context->getTypeFromString('int64');
        $isFull = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::FULL_CACHE, false)),
            $i64->constInt(0, false)
        );
        $fullBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'caching_it_full_cache');
        $doneBb = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'caching_it_done');
        $context->builder->branchIf($isFull, $fullBb, $doneBb);

        $context->builder->positionAtEnd($fullBb);
        $cacheHt = HashTableKeyFilterLlvm::copy($context, $context->helper->loadValue($copy));
        $cacheVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $cacheHt
        );
        $cacheSlot = $context->type->object->propertySlotFor(
            $objPtr,
            'CachingIterator',
            self::PROP_CACHE
        );
        $context->type->object->propertyStore($cacheSlot, $cacheVar, Variable::TYPE_HASHTABLE);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->type->object->markObjectConstructed($objPtr);

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
            'CachingIterator::__construct() flags must be int, got '
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
            'CachingIterator::__construct() expects an object, got '
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
