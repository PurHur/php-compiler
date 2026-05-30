<?php

declare(strict_types=1);

/**
 * Allocate and write boxed {@see __value__} slots in JIT code.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Type as LlvmType;
use PHPLLVM\Value;

final class JitValueBox
{
    private static int $copySeq = 0;

    public static function alloc(Context $context): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        // LLVM alloca is uninitialized; __value__write* calls valueDelref first (issue #AOT heap).
        $map = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($slot, $map['type'])
        );

        return $slot;
    }

    public static function pointer(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast(
            $slot,
            $context->getTypeFromString('__value__*')
        );
    }

    public static function isValueOperand(Variable $var): bool
    {
        if (Variable::TYPE_VALUE === $var->type) {
            return true;
        }
        return null !== $var->objectPropertySlot
            && Variable::TYPE_VALUE === $var->objectPropertyType;
    }

    /**
     * Unwrap {@see __value__value*} to the inner {@see __value__*} (issue #1056 bundle ICmp).
     */
    public static function normalizeValuePtr(Context $context, Value $ptr): Value
    {
        $ptrTy = $ptr->typeOf();
        if (LlvmType::KIND_POINTER !== $ptrTy->getKind()) {
            return $ptr;
        }
        $elemName = $context->getStringFromType($ptrTy->getElementType());
        if ('__value__value' !== $elemName) {
            return $ptr;
        }
        $wrapMap = $context->structFieldMap['__value__value'];
        $inner = $context->builder->structGep($ptr, $wrapMap['value']);

        return $context->builder->pointerCast(
            $inner,
            $context->getTypeFromString('__value__*')
        );
    }

    /**
     * {@see __value__*} for a boxed {@see Variable::TYPE_VALUE} (by-value or alloca slot).
     */
    public static function valuePtrFromVariable(Context $context, Variable $var): Value
    {
        if (null !== $var->valueBoxAliasPtr) {
            return self::normalizeValuePtr($context, $var->valueBoxAliasPtr);
        }
        if (self::isValueOperand($var) && Variable::TYPE_VALUE !== $var->type) {
            $valueType = $context->getTypeFromString('__value__');
            $storage = BasicBlockHelper::entryAlloca($context, $valueType);
            $valueMap = $context->structFieldMap['__value__'];
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
                $context->builder->structGep($storage, $valueMap['type'])
            );
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $var->objectPropertySlot,
                $storage
            );
            return self::normalizeValuePtr($context, self::pointer($context, $storage));
        }
        if (Variable::TYPE_VALUE !== $var->type) {
            return self::valuePtrFromNativeVariable($context, $var);
        }
        if (Variable::KIND_VALUE === $var->kind && $var->functionStaticGlobal) {
            return self::normalizeValuePtr($context, $context->builder->load($var->value));
        }
        if (Variable::KIND_VARIABLE === $var->kind) {
            $llvmType = $context->getStringFromType($var->value->typeOf());
            if ('__value__*' === $llvmType) {
                $ptr = $var->functionStaticGlobal
                    ? $context->builder->load($var->value)
                    : $var->value;

                return self::normalizeValuePtr($context, $ptr);
            }
            if ('__value__' === $llvmType) {
                return self::normalizeValuePtr($context, self::pointer($context, $var->value));
            }
            if ('__string__**' === $llvmType) {
                $str = $context->builder->load($var->value);
                $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $str
                );

                return self::normalizeValuePtr($context, self::pointer($context, $slot));
            }
            if ('__string__*' === $llvmType) {
                $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $var->value
                );

                return self::normalizeValuePtr($context, self::pointer($context, $slot));
            }
            if ('__object__*' === $llvmType) {
                return self::valuePtrFromObjectParam($context, $var->value);
            }

            return self::normalizeValuePtr($context, self::pointer($context, $var->value));
        }
        $valueTy = $var->value->typeOf();
        if (
            LlvmType::KIND_POINTER === $valueTy->getKind()
            && '__value__' === $context->getStringFromType($valueTy->getElementType())
        ) {
            return self::normalizeValuePtr($context, $var->value);
        }
        if ('__object__*' === $context->getStringFromType($valueTy)) {
            return self::valuePtrFromObjectParam($context, $var->value);
        }
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $context->builder->store($var->value, $slot);

        return self::normalizeValuePtr($context, self::pointer($context, $slot));
    }

    /**
     * Box a nullable object param ({@see __object__*} at the LLVM edge, {@see Variable::TYPE_VALUE} in JIT).
     */
    private static function valuePtrFromObjectParam(Context $context, Value $objPtr): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $destPtr = self::pointer($context, $slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $objPtr,
            $objPtr->typeOf()->constNull()
        );
        $nullBlock = BasicBlockHelper::append($context, 'box_obj_param_null');
        $objBlock = BasicBlockHelper::append($context, 'box_obj_param_ptr');
        $done = BasicBlockHelper::append($context, 'box_obj_param_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($objBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $objPtr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return self::normalizeValuePtr($context, $destPtr);
    }

    public static function writeLong(Context $context, Value $slot, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            self::pointer($context, $slot),
            $long
        );
    }

    /**
     * Copy a boxed value from a {@see __value__*} slot into a stack {@see __value__} alloca.
     */
    public static function copyFromPointer(Context $context, Value $destSlot, Value $srcPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $destTy = $context->getStringFromType($destSlot->typeOf());
        $destPtr = '__value__*' === $destTy
            ? self::normalizeValuePtr($context, $destSlot)
            : self::pointer($context, $destSlot);

        $tag = 'v'.(string) self::$copySeq++;
        $stringBlock = BasicBlockHelper::append($context, 'value_copy_string_'.$tag);
        $hashtableBlock = BasicBlockHelper::append($context, 'value_copy_hashtable_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'value_copy_object_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'value_copy_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'value_copy_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'value_copy_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'value_copy_null_'.$tag);
        $done = BasicBlockHelper::append($context, 'value_copy_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );

        $afterString = BasicBlockHelper::append($context, 'value_copy_after_string_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $srcPtr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterHashtable = BasicBlockHelper::append($context, 'value_copy_after_hashtable_'.$tag);
        $context->builder->branchIf($isHashtable, $hashtableBlock, $afterHashtable);

        $context->builder->positionAtEnd($hashtableBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $destPtr,
            $ht
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHashtable);
        $afterObject = BasicBlockHelper::append($context, 'value_copy_after_object_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $srcPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterObject);
        $afterLong = BasicBlockHelper::append($context, 'value_copy_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'value_copy_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        self::writeBool(
            $context,
            $destSlot,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $srcPtr),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'value_copy_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $srcPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
        $context->builder->branchIf($isNull, $nullBlock, $done);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function writeBool(Context $context, Value $slot, Value $bool): void
    {
        $map = $context->structFieldMap['__value__'];
        $ptr = self::pointer($context, $slot);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($ptr, $map['type'])
        );
        $boolTy = $context->getStringFromType($bool->typeOf());
        $boolByte = ('int1' === $boolTy || 'bool' === $boolTy)
            ? $context->builder->zExt($bool, $i8)
            : $context->builder->truncOrBitCast($bool, $i8);
        $valueField = $context->builder->structGep($ptr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($boolByte, $firstByte);
    }

    /**
     * Box a native JIT variable into a temporary {@see __value__*} (closure alias stores, issue #3097).
     */
    public static function valuePtrFromNativeVariable(Context $context, Variable $var): Value
    {
        $slot = self::alloc($context);
        $native = $context->builder->load($var->value);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                self::writeLong($context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_BOOL:
                self::writeLong(
                    $context,
                    $slot,
                    $context->builder->zExt($native, $context->getTypeFromString('int64'))
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    self::pointer($context, $slot),
                    $native
                );
                break;
            case Variable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $native
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $owned
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    self::pointer($context, $slot),
                    $native
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $context->refcount->addref($native);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    self::pointer($context, $slot),
                    $native
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    self::pointer($context, $slot)
                );
                break;
            default:
                throw new \LogicException(
                    'valuePtrFromNativeVariable unsupported type: '.Variable::getStringType($var->type)
                );
        }

        return self::normalizeValuePtr($context, self::pointer($context, $slot));
    }
}
