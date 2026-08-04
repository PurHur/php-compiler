<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT OuterIterator wrappers that snapshot inner `__spl_ht` and walk via
 * Iterator protocol + `__spl_iter_pos` (#27583, #27568).
 *
 * Not listed in {@see SplOuterIteratorHt} — foreach must call rewind/valid/…
 * so NoRewindIterator::rewind can be a no-op (second foreach stays empty).
 *
 * php-src: ext/spl/spl_iterators.c — spl_norewind_it_* / InfiniteIterator
 */
final class SplHtPosIteratorJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const PROP_POS = '__spl_iter_pos';

    /** Rewind resets position to 0 (IteratorIterator / InfiniteIterator). */
    public const REWIND_RESET = 0;

    /** Rewind is a no-op (NoRewindIterator). */
    public const REWIND_NOOP = 1;

    /** Next wraps to 0 when past the end (InfiniteIterator). */
    public const NEXT_WRAP = 1;

    public const NEXT_STOP = 0;

    public static function compileConstruct(
        Context $context,
        JITVariable $receiver,
        JITVariable $inner,
        string $className
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $innerObj = self::loadObject($context, $inner);
        $srcHtVar = $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $innerObj)
        );
        $copy = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto($context, $copy, $srcHtVar);
        $slot = $context->type->object->propertySlotFor($obj, $className, self::PROP_HT);
        $context->type->object->propertyStore($slot, $copy, JITVariable::TYPE_HASHTABLE);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $i64->constInt(0, false));
        $context->type->object->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compileRewind(
        Context $context,
        JITVariable $receiver,
        string $className,
        int $rewindMode
    ): Value {
        if (self::REWIND_NOOP === $rewindMode) {
            return self::voidResult($context);
        }
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $i64->constInt(0, false));

        return self::voidResult($context);
    }

    public static function compileValid(
        Context $context,
        JITVariable $receiver,
        string $className
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj, $className);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $nonEmpty = $context->builder->icmp(Builder::INT_SGT, $n64, $i64->constInt(0, false));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $ok = $context->builder->and($nonEmpty, $inRange);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return $slot;
    }

    public static function compileCurrent(
        Context $context,
        JITVariable $receiver,
        string $className
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj, $className);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->truncOrBitCast($pos, $sizeT);

        return HashTableHelper::readIndexedToValueBox($context, $ht, $idx)->value;
    }

    public static function compileKey(
        Context $context,
        JITVariable $receiver,
        string $className
    ): Value {
        // Packed snapshot: key === position (ArrayIterator int keys).
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    public static function compileNext(
        Context $context,
        JITVariable $receiver,
        string $className,
        int $nextMode
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_POS);
        $i64 = $context->getTypeFromString('int64');
        $next = $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false));
        if (self::NEXT_WRAP !== $nextMode) {
            self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $next);

            return self::voidResult($context);
        }

        $ht = self::htPtr($context, $obj, $className);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $past = $context->builder->icmp(Builder::INT_SGE, $next, $n64);
        $wrapped = $context->builder->select($past, $i64->constInt(0, false), $next);
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_POS, $wrapped);

        return self::voidResult($context);
    }

    private static function htPtr(Context $context, Value $obj, string $className): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type || JitValueBox::isValueOperand($receiver)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SPL HT-pos iterator method requires an object receiver');
    }

    private static function loadLongProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop
    ): Value {
        $slot = $context->type->object->propertyFetch($obj, $className, $prop);
        if (JITVariable::TYPE_NATIVE_LONG === $slot->type) {
            return $context->helper->loadValue($slot);
        }
        if (JITVariable::TYPE_VALUE === $slot->type || JitValueBox::isValueOperand($slot)) {
            return $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $slot)
            );
        }

        throw new \LogicException("property {$prop} must be native long");
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $i64
    ): void {
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $i64);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $var,
            JITVariable::TYPE_NATIVE_LONG
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
