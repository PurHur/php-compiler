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
        BasicBlockHelper::ensureOpenInsertBlock($context, 'value_box_alloc_cont');
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        BasicBlockHelper::ensureOpenInsertBlock($context, 'value_box_init_cont');
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
     * Ensure later reads observe a prior {@see assignToPointer} (bootstrap concat → strcmp; #1492).
     */
    public static function publishAfterWrite(Context $context, Value $valuePtr): void
    {
        $valuePtr = self::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
    }

    /**
     * {@see __value__*} for a boxed {@see Variable::TYPE_VALUE} (by-value or alloca slot).
     */
    public static function valuePtrFromVariable(Context $context, Variable $var): Value
    {
        if (null !== $var->valueBoxAliasPtr && null === $var->staticPropertyGlobal) {
            return self::normalizeValuePtr($context, $var->valueBoxAliasPtr);
        }
        // `$r = &Class::$prop` must read the live module global, not the fetch snapshot (#32036).
        if (
            null !== $var->staticPropertyGlobal
            && Variable::TYPE_VALUE === ($var->staticPropertyType ?? $var->type)
        ) {
            $heapPtr = $context->builder->pointerCast(
                $context->builder->load($var->staticPropertyGlobal),
                $context->getTypeFromString('__value__*')
            );

            return self::normalizeValuePtr($context, $heapPtr);
        }
        // Return-by-ref from `$this->prop` must alias the heap property slot, not the
        // stack copy materialized by propertyFetch (issue #4054, Zend ZEND_RETURN_BY_REF).
        if (
            null !== $var->objectPropertySlot
            && Variable::TYPE_VALUE === $var->objectPropertyType
        ) {
            $heapPtr = $context->builder->pointerCast(
                $context->builder->load($var->objectPropertySlot),
                $context->getTypeFromString('__value__*')
            );

            return self::normalizeValuePtr($context, $heapPtr);
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
            // By-ref NestedJIT formals / caller args are often `__value__*` KIND_VALUE while
            // the CFG type still says NATIVE_LONG (or similar). Boxing via writeLong then
            // passes the pointer as i64 and fails module verify (#22642).
            $llvmType = $context->getStringFromType($var->value->typeOf());
            if ('__value__*' === $llvmType) {
                return self::normalizeValuePtr($context, $var->value);
            }
            if ('__value__**' === $llvmType) {
                return self::normalizeValuePtr($context, $context->builder->load($var->value));
            }
            if ('__value__' === $llvmType && Variable::KIND_VARIABLE === $var->kind) {
                return self::normalizeValuePtr($context, self::pointer($context, $var->value));
            }

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
        $valueTyName = $context->getStringFromType($valueTy);
        // KIND_VALUE operands may still be __value__** slots after NestedJIT mid-{main} (#21041).
        if ('__value__**' === $valueTyName) {
            return self::normalizeValuePtr($context, $context->builder->load($var->value));
        }
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
     * Box a nullable {@see __object__*} into a fresh {@see __value__*} (#17954).
     */
    public static function nullableObjectToValuePtr(Context $context, Value $objPtr): Value
    {
        return self::valuePtrFromObjectParam($context, $objPtr);
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
        // Surface TYPE_VALUE/NATIVE_LONG confusion at emit time (#22642 module verify).
        $assert = getenv('PHP_COMPILER_LLVM_ASSERT');
        if ('1' === $assert || 'true' === strtolower((string) $assert)) {
            $longTy = $context->getStringFromType($long->typeOf());
            if ('int64' !== $longTy && 'long long' !== $longTy) {
                $frames = [];
                foreach (\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
                    $file = isset($frame['file']) ? \basename((string) $frame['file']) : '?';
                    $line = $frame['line'] ?? '?';
                    $fn = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
                    $frames[] = $file.':'.$line.' '.$fn;
                }
                throw new \LogicException(
                    'JitValueBox::writeLong: second arg type '.$longTy.' (want int64) — #22642'
                    ."\n  via ".implode("\n  via ", $frames)
                );
            }
            $slotTy = $context->getStringFromType($slot->typeOf());
            if ('int64*' === $slotTy || 'long long*' === $slotTy) {
                throw new \LogicException(
                    'JitValueBox::writeLong: slot is '.$slotTy.' (want __value__*) — #22642'
                );
            }
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            self::pointer($context, $slot),
            $long
        );
    }

    /**
     * Assign a JIT variable into an existing {@see __value__*} slot (by-ref capture / param).
     */
    public static function assignToPointer(Context $context, Value $destPtr, Variable $value): void
    {
        $destPtr = self::normalizeValuePtr($context, $destPtr);
        switch ($value->type) {
            case Variable::TYPE_VALUE:
                self::copyIntoPointer(
                    $context,
                    $destPtr,
                    self::valuePtrFromVariable($context, $value)
                );

                return;
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $destPtr,
                    $context->helper->loadValue($value)
                );

                return;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $destPtr,
                    $context->helper->loadValue($value)
                );

                return;
            case Variable::TYPE_NATIVE_BOOL:
                $boolVal = $context->helper->loadValue($value);
                $i32 = $context->getTypeFromString('int32');
                $context->builder->call(
                    $context->lookupFunction('__value__writeBool'),
                    $destPtr,
                    $context->builder->zExt(
                        $context->builder->truncOrBitCast($boolVal, $context->getTypeFromString('int1')),
                        $i32
                    )
                );

                return;
            case Variable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);

                return;
            case Variable::TYPE_STRING:
                // Fresh concat/allocation passes a runtime __string__* in KIND_VALUE; do not
                // lowerDominating through compileTimeLiteral (standalone AOT strlen→0, #15642).
                $strPtr = Variable::KIND_VALUE === $value->kind && null !== $value->value
                    ? $value->value
                    : JitStringArg::lowerDominating($context, $value, 'value box assign');
                $strTy = $context->getStringFromType($strPtr->typeOf());
                if (JitStringArg::isStringPtrPtrType($strTy)) {
                    // Function-static / alloca slots are __string__**; writeString wants * (#31966).
                    $strPtr = $context->builder->load($strPtr);
                }
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $strPtr
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $destPtr,
                    $owned
                );

                return;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    $destPtr,
                    $context->helper->loadValue($value)
                );

                return;
            case Variable::TYPE_HASHTABLE:
                $ht = $context->helper->loadValue($value);
                // writeHashtable addrefs (#24226).
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    $ht
                );

                return;
        }
        if (ArrayBuiltinHelper::isNativeArray($value->type)) {
            $ht = ArrayBuiltinHelper::loadHashTable($context, $value);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $ht
            );

            return;
        }
        throw new \LogicException(
            'assignToPointer: unsupported source type '.Variable::getStringType($value->type)
        );
    }

    /**
     * Promote a scoped variable to a boxed {@see __value__} stack slot so closure
     * {@code use (&$var)} shares one lvalue with the enclosing scope (issue #72).
     */
    public static function promoteNativeLvalueToValueBox(Context $context, Variable $var): void
    {
        if (null !== $var->valueBoxAliasPtr) {
            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            // Script-global slots are __value__**; always re-load via valuePtrFromVariable
            // rather than caching one load() as valueBoxAliasPtr (#24162, #24009).
            if ($var->functionStaticGlobal && Variable::KIND_VALUE === $var->kind) {
                return;
            }
            $var->valueBoxAliasPtr = self::valuePtrFromVariable($context, $var);

            return;
        }
        $slot = self::alloc($context);
        $ptr = self::pointer($context, $slot);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                self::writeLong($context, $slot, $context->helper->loadValue($var));
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
                    $context->helper->loadValue($var)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                self::writeBool($context, $slot, $context->helper->loadValue($var));
                break;
            case Variable::TYPE_NULL:
                $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
                break;
            case Variable::TYPE_STRING:
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $context->helper->loadValue($var)
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    $ptr,
                    $context->helper->loadValue($var)
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $ht = $context->helper->loadValue($var);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $ptr,
                    $ht
                );
                break;
            default:
                throw new \LogicException(
                    'promoteNativeLvalueToValueBox: unsupported type '.Variable::getStringType($var->type)
                );
        }
        $var->type = Variable::TYPE_VALUE;
        $var->kind = Variable::KIND_VARIABLE;
        $var->value = $slot;
        $var->valueBoxAliasPtr = $ptr;
    }

    /**
     * Copy a boxed value from {@see __value__*} to {@see __value__*} (by-ref assignment).
     */
    public static function copyIntoPointer(Context $context, Value $destPtr, Value $srcPtr): void
    {
        self::copyBetweenPointers($context, self::normalizeValuePtr($context, $destPtr), $srcPtr);
    }

    /**
     * Copy a boxed value from a {@see __value__*} slot into a stack {@see __value__} alloca.
     */
    public static function copyFromPointer(Context $context, Value $destSlot, Value $srcPtr): void
    {
        self::copyBetweenPointers($context, self::pointer($context, $destSlot), $srcPtr);
    }

    private static function copyBetweenPointers(Context $context, Value $destPtr, Value $srcPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — HT slots may store VM TYPE_STRING (4) or JIT (4|0x80).
        // Unmasked compare missed VM tags → empty copy → NestedJIT toString strlen=0 (#21921).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

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
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
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
        // writeHashtable addrefs internally (same as writeObject, #24226).
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
        // writeObject addrefs internally (#4096); do not addref here.
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
        // __value__readLong has no TYPE_NATIVE_BOOL arm (returns 0) — #21892 / JitZendScalarCast.
        $boolByte = self::readBoolByte($context, $srcPtr);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $destPtr,
            $context->builder->zExt(
                $context->builder->icmp(
                    Builder::INT_NE,
                    $boolByte,
                    $context->getTypeFromString('int8')->constInt(0, false)
                ),
                $i32
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
        BasicBlockHelper::branchToFreshContinue($context, 'after_value_copy_'.$tag);
    }

    /**
     * Read boxed bool payload (writeBool stores int8 at value[0]).
     * Do not use {@see __value__readLong} — no NATIVE_BOOL arm (#21892).
     */
    public static function readBoolByte(Context $context, Value $valuePtr): Value
    {
        $valuePtr = self::normalizeValuePtr($context, $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $bytePtr = $context->builder->pointerCast(
            $context->builder->structGep($valuePtr, $map['value']),
            $i8->pointerType(0)
        );

        return $context->builder->load($bytePtr);
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
        $i1 = $context->getTypeFromString('int1');
        $boolByte = $context->builder->zExt(
            $context->builder->truncOrBitCast($bool, $i1),
            $i8
        );
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
        // By-ref capture / param formals may keep a scalar inferred type while LLVM storage
        // is already a boxed {@see __value__*} (module verify: writeLong gets __value__* — #22642).
        $storageTy = $context->getStringFromType($var->value->typeOf());
        if ('__value__*' === $storageTy) {
            return self::normalizeValuePtr($context, $var->value);
        }
        if ('__value__' === $storageTy) {
            return self::normalizeValuePtr($context, self::pointer($context, $var->value));
        }

        $slot = self::alloc($context);
        $native = Variable::KIND_VALUE === $var->kind
            ? $var->value
            : $context->builder->load($var->value);
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                self::writeLong($context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_BOOL:
                self::writeBool($context, $slot, $native);
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
                if (ArrayBuiltinHelper::isNativeArray($var->type)) {
                    $ht = ArrayBuiltinHelper::loadHashTable($context, $var);
                    $context->builder->call(
                        $context->lookupFunction('__value__writeHashtable'),
                        self::pointer($context, $slot),
                        $ht
                    );
                    break;
                }
                throw new \LogicException(
                    'valuePtrFromNativeVariable unsupported type: '.Variable::getStringType($var->type)
                );
        }

        return self::normalizeValuePtr($context, self::pointer($context, $slot));
    }

    /**
     * Instance method calls may return {@see __value__} or {@see __value__*} (#3098, #4012).
     * Indirect dispatch also boxes native LLVM scalars (SplObjectStorage::count(), etc.).
     */
    public static function coerceToValuePtrForStore(Context $context, Value $raw): Value
    {
        $tyName = $context->getStringFromType($raw->typeOf());
        if ('__value__*' === $tyName) {
            return self::normalizeValuePtr($context, $raw);
        }
        if ('__value__' === $tyName) {
            $slot = self::alloc($context);
            $context->builder->store($raw, $slot);

            return self::pointer($context, $slot);
        }
        if ('int64' === $tyName) {
            $slot = self::alloc($context);
            self::writeLong($context, $slot, $raw);

            return self::pointer($context, $slot);
        }
        if ('int1' === $tyName || 'bool' === $tyName) {
            $slot = self::alloc($context);
            self::writeBool($context, $slot, $raw);

            return self::pointer($context, $slot);
        }
        if ('double' === $tyName) {
            $slot = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                self::pointer($context, $slot),
                $raw
            );

            return self::pointer($context, $slot);
        }
        if ('__string__*' === $tyName) {
            $slot = self::alloc($context);
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $raw
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                self::pointer($context, $slot),
                $owned
            );

            return self::pointer($context, $slot);
        }
        if ('__object__*' === $tyName) {
            $slot = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                self::pointer($context, $slot),
                $raw
            );

            return self::pointer($context, $slot);
        }
        if ('__hashtable__*' === $tyName) {
            $slot = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                self::pointer($context, $slot),
                $raw
            );

            return self::pointer($context, $slot);
        }
        // Void LLVM returns (user __construct via RuntimeIndirectInstanceMethodCall) (#27302 / #27156).
        if ('void' === $tyName) {
            $slot = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                self::pointer($context, $slot)
            );

            return self::pointer($context, $slot);
        }

        return self::normalizeValuePtr($context, $raw);
    }

    /**
     * Read a nullable string return from a boxed union (ternary string|null, issue #8555).
     *
     * {@see __value__readString} returns null for TYPE_NULL / non-string tags (Value.pre).
     */
    public static function readStringOrNull(Context $context, Variable $var): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            self::valuePtrFromVariable($context, $var)
        );
    }
}
