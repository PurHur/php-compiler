<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Thin-AOT CachingIterator flag storage — getFlags/setFlags on `__flags` (#31694 AOT leftover).
 *
 * Construct/getCache already snapshot `__spl_ht` / `__spl_cache`; setFlags was falling through to
 * VM SplCachingIteratorStorage and aborting when thin-AOT never populated that store.
 *
 * php-src: ext/spl/spl_iterators.c — zim_CachingIterator_getFlags / zim_CachingIterator_setFlags
 */
final class CachingIteratorJitHelper
{
    public const CLASS_NAME = 'CachingIterator';

    public const PROP_FLAGS = '__flags';

    public static function compileGetFlags(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $flagsSlot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_FLAGS);
        $flags = JITVariable::TYPE_NATIVE_LONG === $flagsSlot->type
            ? $context->helper->loadValue($flagsSlot)
            : $context->builder->call(
                $context->lookupFunction('__value__toLong'),
                JitValueBox::valuePtrFromVariable($context, $flagsSlot)
            );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $flags
        );

        return $slot;
    }

    /**
     * Z_PARAM_LONG — soft-null DEP+0 outside strict_types (#31694).
     */
    public static function compileSetFlags(
        Context $context,
        JITVariable $receiver,
        JITVariable $flagsArg
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $flags = JitStrictIntArg::lower(
            $context,
            $flagsArg,
            'CachingIterator::setFlags',
            1,
            'flags'
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_NAME, self::PROP_FLAGS),
            new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $flags),
            JITVariable::TYPE_NATIVE_LONG
        );

        return self::voidResult($context);
    }

    public static function storeFlags(Context $context, Value $objPtr, Value $flags): void
    {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($objPtr, self::CLASS_NAME, self::PROP_FLAGS),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $flags
            ),
            JITVariable::TYPE_NATIVE_LONG
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

        throw new \LogicException(
            'CachingIterator method expects object receiver, got '
            .JITVariable::getStringType($receiver->type)
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
