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
 * Thin-AOT ArrayObject — `__spl_ht` storage (#26823, #27286, ext/spl/spl_array.c).
 */
final class ArrayObjectJitHelper
{
    public const PROP_HT = '__spl_ht';

    public const CLASS_NAME = 'ArrayObject';

    /**
     * Classes whose `$obj[] =` must append into object `__spl_ht`, not overwrite the
     * value-box as a bare hashtable (#27286 — reserveAppendSlot on the object clobbers it).
     */
    public static function supportsEmptyDimAppend(?string $containerUserType): bool
    {
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $ut = strtolower(ltrim($containerUserType, '\\'));

        return 'arrayobject' === $ut
            || 'arrayiterator' === $ut
            || 'recursivearrayiterator' === $ut;
    }

    /**
     * Resolve `__spl_ht` for empty-dim append (`$o[] =`) from TYPE_OBJECT or boxed TYPE_VALUE.
     */
    public static function backingHashtableForAppend(Context $context, JITVariable $receiver): JITVariable
    {
        $obj = self::loadObject($context, $receiver);

        return $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
        );
    }

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

    /** php-src spl_array_method_append — next numeric index into storage. */
    public static function compileAppend(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        HashTableHelper::addElement($context, $htVar, $value, null);

        return self::voidResult($context);
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

        // Not a PHP superglobal — pass null (HashTableReadLlvm 4th arg; #27244 / NestedJIT).
        return HashTableReadLlvm::readValueBoxKeyToValueBox($context, $ht, $boxedKey, null)->value;
    }

    public static function compileOffsetSet(
        Context $context,
        JITVariable $receiver,
        JITVariable $key,
        JITVariable $value
    ): Value {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        // php-src: null offset → append (zend_hash_next_index_insert); do not coerce to '' (#27286).
        if (JITVariable::TYPE_NULL === $key->type || ($key->isNullConstant ?? false)) {
            HashTableHelper::addElement($context, $htVar, $value, null);

            return self::voidResult($context);
        }
        if (JITVariable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            $valPtr = \PHPCompiler\JIT\HashTableReadLlvm::valuePtrFromDim($context, $key);
            $valueMap = $context->structFieldMap['__value__'];
            $i8 = $context->getTypeFromString('int8');
            $typeByte = $context->builder->load(
                $context->builder->structGep($valPtr, $valueMap['type'])
            );
            $fn = $context->builder->getInsertBlock()->getParent();
            $nullBb = $fn->appendBasicBlock('ao_offsetset_null_append');
            $keyedBb = $fn->appendBasicBlock('ao_offsetset_keyed');
            $doneBb = $fn->appendBasicBlock('ao_offsetset_done');
            $context->builder->branchIf(
                $context->builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(JITVariable::TYPE_NULL, false)
                ),
                $nullBb,
                $keyedBb
            );
            $context->builder->positionAtEnd($nullBb);
            HashTableHelper::addElement($context, $htVar, $value, null);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($keyedBb);
            HashTableHelper::setValueBoxKey($context, $ht, $key, $value);
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($doneBb);

            return self::voidResult($context);
        }
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
