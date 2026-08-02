<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Thin-AOT ArrayObject — `__spl_ht` storage (#26823, ext/spl/spl_array.c).
 */
final class ArrayObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const CLASS_NAME = 'ArrayObject';

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    public static function compileGetArrayCopy(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $copy = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        HashTableHelper::spreadInto(
            $context,
            $copy,
            new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht)
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $context->helper->loadValue($copy)
        );

        return $slot;
    }

    public static function compileOffsetGet(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $boxedKey = self::asValueBoxKey($context, $key);

        return HashTableReadLlvm::readValueBoxKeyToValueBox($context, $ht, $boxedKey)->value;
    }

    public static function compileOffsetSet(
        Context $context,
        JITVariable $receiver,
        JITVariable $key,
        JITVariable $value
    ): Value {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        HashTableHelper::setValueBoxKey($context, $ht, self::asValueBoxKey($context, $key), $value);

        return self::voidResult($context);
    }

    public static function compileOffsetExists(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $isSet = HashTableHelper::offsetIsSetDim($context, $ht, self::asValueBoxKey($context, $key));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $isSet);

        return $slot;
    }

    public static function compileOffsetUnset(Context $context, JITVariable $receiver, JITVariable $key): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        HashTableWriteLlvm::unsetValueBoxKey($context, $ht, self::asValueBoxKey($context, $key));

        return self::voidResult($context);
    }

    /** Dim keys from literals are TYPE_STRING / NATIVE_LONG — box for HT value-key APIs. */
    private static function asValueBoxKey(Context $context, JITVariable $key): JITVariable
    {
        if (JITVariable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            return $key;
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($key->type) {
            case JITVariable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $context->helper->loadValue($key)
                );
                break;
            case JITVariable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($key));
                break;
            case JITVariable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool($context, $slot, $context->helper->loadValue($key));
                break;
            case JITVariable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                break;
            default:
                throw new \LogicException(
                    'ArrayObject offset key type '.JITVariable::getStringType($key->type).' unsupported in thin AOT'
                );
        }

        return new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
    }

    private static function htPtr(Context $context, Value $obj): Value
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
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('ArrayObject method requires an object receiver');
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
