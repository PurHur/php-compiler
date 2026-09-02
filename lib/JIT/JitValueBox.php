<?php

declare(strict_types=1);

/**
 * Allocate and write boxed {@see __value__} slots in JIT code.
 */

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Type as LlvmType;
use PHPLLVM\Value;

final class JitValueBox
{
    public static function alloc(Context $context): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'value_box_alloc_cont');
        // Entry alloca + TYPE_NULL after the alloca group — not in the allocating CFG arm —
        // so freeDeadVariables on sibling returns does not valueDelref stack garbage (#23472).
        $slot = BasicBlockHelper::entryAllocaValueBox($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'value_box_init_cont');

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
    /**
     * `__value__*` for `function &f()` / method by-ref return (Zend ZEND_RETURN_BY_REF).
     *
     * Unlike {@see valuePtrFromVariable}, property lvalues alias the live heap box in the
     * object's void** slot — a stack snapshot would dangle after the callee returns (#34717).
     */
    public static function valuePtrForByRefReturn(Context $context, Variable $var): Value
    {
        if (
            null !== $var->objectPropertySlot
            && Variable::TYPE_VALUE === $var->objectPropertyType
        ) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'byref_return_prop');
            // Use the FETCH_WRITE-time void** — dominatingSlotPtr reloads the receiver via
            // objectPropertyReceiverOp and rematerializes propertySlotPtr; for value-boxed
            // untyped/mixed formals that GEP can miss the live cell (#34721 / re-#34717).
            $slot = $var->objectPropertySlot;
            $valuePtrTy = $context->getTypeFromString('__value__*');
            $voidPtr = $context->getTypeFromString('void*');
            $loaded = $context->builder->pointerCast(
                $context->builder->load($slot),
                $valuePtrTy
            );
            $isNull = $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $loaded,
                $valuePtrTy->constNull()
            );
            $fn = BasicBlockHelper::parentFunction($context);
            $allocBb = $fn->appendBasicBlock('byref_ret_prop_alloc');
            $readyBb = $fn->appendBasicBlock('byref_ret_prop_ready');
            $entryBb = $context->builder->getInsertBlock();
            $context->builder->branchIf($isNull, $allocBb, $readyBb);

            $context->builder->positionAtEnd($allocBb);
            $heapVal = $context->memory->malloc($context->getTypeFromString('__value__'));
            $heapPtrAlloc = $context->builder->pointerCast($heapVal, $valuePtrTy);
            $valueMap = $context->structFieldMap['__value__'];
            $context->builder->store(
                $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
                $context->builder->structGep($heapVal, $valueMap['type'])
            );
            $context->builder->store(
                $context->builder->pointerCast($heapPtrAlloc, $voidPtr),
                $slot
            );
            $context->builder->branch($readyBb);

            $context->builder->positionAtEnd($readyBb);
            $heapPtr = $context->builder->phi($valuePtrTy, 'byref_ret_prop_phi');
            $heapPtr->addIncoming($heapPtrAlloc, $allocBb);
            $heapPtr->addIncoming($loaded, $entryBb);

            return self::normalizeValuePtr($context, $heapPtr);
        }

        return self::valuePtrFromVariable($context, $var);
    }

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
        // By-value read of a property: copy into a stack box so casual stores do not
        // mutate the instance. By-ref return uses {@see valuePtrForByRefReturn} (#4054).
        if (
            null !== $var->objectPropertySlot
            && Variable::TYPE_VALUE === $var->objectPropertyType
        ) {
            $storage = self::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__object__load_boxed_value_slot'),
                ObjectInstancePropertyLlvm::dominatingSlotPtr($context->type->object, $var),
                $storage
            );

            return self::normalizeValuePtr($context, self::pointer($context, $storage));
        }
        if (self::isValueOperand($var) && Variable::TYPE_VALUE !== $var->type) {
            $storage = BasicBlockHelper::entryAllocaValueBox($context);
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
            // Undefined `$u ?? …` loads phpc_script_global_*; parentless load fails
            // module verify after NestedJIT helper link (#32445).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'script_global_load');
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
                $slot = BasicBlockHelper::entryAllocaValueBox($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $str
                );

                return self::normalizeValuePtr($context, self::pointer($context, $slot));
            }
            if ('__string__*' === $llvmType) {
                $slot = BasicBlockHelper::entryAllocaValueBox($context);
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
        // LLVM by-value __value__ formals (KIND_VALUE): store into a reachable alloca
        // before returning its pointer — sealed insert BBs left the slot null and by-ref
        // `$r = $v` copied TYPE_NULL into the caller lvalue (e06_byref, #32654).
        BasicBlockHelper::repositionToLastOpenIfInsertLost($context);
        if (!BasicBlockHelper::unsealAndContinue($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'value_ptr_mat');
        }
        $slot = self::alloc($context);
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
        $slot = BasicBlockHelper::entryAllocaValueBox($context);
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
        // compileTimeLong is also stamped on TYPE_NATIVE_BOOL CONST_FETCH true/false (#26774)
        // and copied onto VALUE boxes after `$x = true`. Only integer-typed values may use
        // the writeLong shortcut — else AOT stores int(1) for bool(true) (#33761).
        if (null !== $value->compileTimeLong && Variable::TYPE_NATIVE_LONG === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $destPtr,
                $context->getTypeFromString('int64')->constInt($value->compileTimeLong, false)
            );

            return;
        }
        switch ($value->type) {
            case Variable::TYPE_VALUE:
                if (
                    Variable::KIND_VARIABLE === $value->kind
                ) {
                    $llvmType = $context->getStringFromType($value->value->typeOf());
                    if ('__value__' === $llvmType || '__value__*' === $llvmType) {
                        // Use the CV stack slot directly — valuePtrFromVariable may box the
                        // LLVM formal into a fresh null alloca (e06_byref `$r = $v`).
                        BasicBlockHelper::repositionToLastOpenIfInsertLost($context);
                        if (!BasicBlockHelper::unsealAndContinue($context)) {
                            BasicBlockHelper::ensureOpenInsertBlock($context, 'assign_cv_box');
                        }
                        self::copyIntoPointer(
                            $context,
                            $destPtr,
                            '__value__' === $llvmType
                                ? self::pointer($context, $value->value)
                                : self::normalizeValuePtr($context, $value->value)
                        );

                        return;
                    }
                }
                if (
                    Variable::KIND_VALUE === $value->kind
                    && '__value__' === $context->getStringFromType($value->value->typeOf())
                ) {
                    BasicBlockHelper::repositionToLastOpenIfInsertLost($context);
                    if (!BasicBlockHelper::unsealAndContinue($context)) {
                        BasicBlockHelper::ensureOpenInsertBlock($context, 'assign_formal_box');
                    }
                    $slot = self::alloc($context);
                    $slotPtr = self::pointer($context, $slot);
                    $context->builder->call(
                        $context->lookupFunction('__value__valueDelref'),
                        $slotPtr
                    );
                    $context->builder->store($value->value, $slot);
                    self::copyIntoPointer(
                        $context,
                        $destPtr,
                        $slotPtr
                    );
                    $context->builder->call(
                        $context->lookupFunction('__value__valueDelref'),
                        $slotPtr
                    );
                    self::releaseEphemeralAssignSource($context, $value);

                    return;
                }
                if (Variable::KIND_VALUE === $value->kind) {
                    // Untyped callee formals arrive as by-value __value__ at the LLVM edge.
                    // Script globals and other slots use __value__** — fall through to
                    // valuePtrFromVariable (loads before structGep). #32660 regressed i10/i08.
                    $valTyName = $context->getStringFromType($value->value->typeOf());
                    if ('__value__' === $valTyName || '__value__*' === $valTyName) {
                        BasicBlockHelper::repositionToLastOpenIfInsertLost($context);
                        if (!BasicBlockHelper::unsealAndContinue($context)) {
                            BasicBlockHelper::ensureOpenInsertBlock($context, 'assign_formal_ptr');
                        }
                        $slot = self::alloc($context);
                        $slotPtr = self::pointer($context, $slot);
                        $context->builder->call(
                            $context->lookupFunction('__value__valueDelref'),
                            $slotPtr
                        );
                        if ('__value__' === $valTyName) {
                            $context->builder->store($value->value, $slot);
                        } else {
                            self::copyFromPointer(
                                $context,
                                $slot,
                                self::normalizeValuePtr($context, $value->value)
                            );
                        }
                        self::copyIntoPointer(
                            $context,
                            $destPtr,
                            $slotPtr
                        );
                        $context->builder->call(
                            $context->lookupFunction('__value__valueDelref'),
                            $slotPtr
                        );
                        self::releaseEphemeralAssignSource($context, $value);

                        return;
                    }
                }
                self::copyIntoPointer(
                    $context,
                    $destPtr,
                    self::valuePtrFromVariable($context, $value)
                );
                self::releaseEphemeralAssignSource($context, $value);

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
                self::writeStringToValuePtrByAddref($context, $destPtr, $strPtr);

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
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    $ht
                );
                self::releaseEphemeralAssignSource($context, $value);

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
     * Drop an ephemeral assign-source value box after its payload was copied into the lvalue
     * (Zend zval_ptr_dtor on the source zval after COPY). Skips named locals (#36215).
     */
    public static function releaseEphemeralAssignSource(Context $context, Variable $source): void
    {
        if (Variable::KIND_VALUE !== $source->kind) {
            return;
        }
        if (Variable::TYPE_VALUE === $source->type) {
            if (Variable::KIND_VARIABLE === $source->kind) {
                $context->builder->call(
                    $context->lookupFunction('__value__valueDelref'),
                    self::pointer($context, $source->value)
                );

                return;
            }
            $tyName = $context->getStringFromType($source->value->typeOf());
            if ('__value__*' === $tyName) {
                $context->builder->call(
                    $context->lookupFunction('__value__valueDelref'),
                    self::normalizeValuePtr($context, $source->value)
                );
            }

            return;
        }
        if ($source->type & Variable::IS_REFCOUNTED) {
            $ptr = Variable::KIND_VALUE === $source->kind
                ? $source->value
                : $context->helper->loadValue($source);
            $context->refcount->delref($ptr);
        }
    }

    /**
     * After {@see copyFromPointer} / {@see copyIntoPointer} into $dest, drop the source box
     * when it is a distinct stack slot (call-result temps are KIND_VARIABLE) (#36215).
     */
    public static function releaseAssignSourceBoxAfterCopy(
        Context $context,
        Variable $dest,
        Variable $source
    ): void {
        if (Variable::TYPE_VALUE !== $source->type) {
            self::releaseEphemeralAssignSource($context, $source);

            return;
        }
        if ($dest->value === $source->value) {
            return;
        }
        if (Variable::KIND_VALUE === $source->kind) {
            self::releaseEphemeralAssignSource($context, $source);

            return;
        }
        if (Variable::KIND_VARIABLE === $source->kind) {
            $context->builder->call(
                $context->lookupFunction('__value__valueDelref'),
                self::pointer($context, $source->value)
            );
        }
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
                self::writeStringToValuePtrByAddref(
                    $context,
                    $ptr,
                    $context->helper->loadValue($var)
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
        // Zend: drop the source box after COPY into the destination lvalue (#36215).
        $context->builder->call(
            $context->lookupFunction('__value__valueDelref'),
            self::normalizeValuePtr($context, $srcPtr)
        );
    }

    /**
     * Copy a boxed value from a {@see __value__*} slot into a stack {@see __value__} alloca.
     */
    public static function copyFromPointer(Context $context, Value $destSlot, Value $srcPtr): void
    {
        self::copyBetweenPointers($context, self::pointer($context, $destSlot), $srcPtr);
        // Zend: zval_ptr_dtor on the source after COPY into the lvalue (#36215).
        $context->builder->call(
            $context->lookupFunction('__value__valueDelref'),
            self::normalizeValuePtr($context, $srcPtr)
        );
    }

    /**
     * Copy between two {@see __value__*} pointers — delegates to outlined __value__copy (#36193).
     */
    private static function copyBetweenPointers(Context $context, Value $destPtr, Value $srcPtr): void
    {
        if (!BasicBlockHelper::unsealAndContinue($context)) {
            BasicBlockHelper::ensureOpenInsertBlockReplacingVoidReturn($context, 'value_copy_cont');
        }
        Builtin\ValueBoxCopyJit::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__value__copy'),
            $destPtr,
            $srcPtr
        );
    }

    /**
     * Share a refcounted {@see __string__} into a value box (Zend zend_string_copy semantics).
     * {@see __string__separate} is for mutation / hashtable-key ownership, not assignment copy.
     */
    private static function writeStringToValuePtrByAddref(Context $context, Value $destPtr, Value $strPtr): void
    {
        $context->refcount->addref($strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $strPtr
        );
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
        if (null !== $var->longArithOverflowPromoted) {
            $materialized = JitLongArithOverflow::materializeOverflowableNativeLong($context, $var);

            return self::valuePtrFromVariable($context, $materialized);
        }
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
        if (Variable::KIND_VALUE === $var->kind) {
            $native = $var->value;
        } else {
            $storageTy = $context->getStringFromType($var->value->typeOf());
            $native = (
                '__object__*' === $storageTy
                || '__string__*' === $storageTy
                || '__hashtable__*' === $storageTy
            )
                ? $var->value
                : $context->builder->load($var->value);
        }
        // Typed native instance props are KIND_VALUE whose LLVM value is a pointer to the
        // scalar (int64*/double*/int1*), not the bits (#24008). Helper::loadValue loads that
        // pointer; boxing must too or writeLong gets int64* and module verify fails (#33018).
        $nativeTy = $context->getStringFromType($native->typeOf());
        switch ($var->type) {
            case Variable::TYPE_NATIVE_LONG:
                if ('int64*' === $nativeTy || 'long long*' === $nativeTy) {
                    $native = $context->builder->load($native);
                }
                self::writeLong($context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_BOOL:
                if ('int1*' === $nativeTy || 'i1*' === $nativeTy) {
                    $native = $context->builder->load($native);
                }
                self::writeBool($context, $slot, $native);
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                if ('double*' === $nativeTy) {
                    $native = $context->builder->load($native);
                }
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    self::pointer($context, $slot),
                    $native
                );
                break;
            case Variable::TYPE_STRING:
                self::writeStringToValuePtrByAddref(
                    $context,
                    self::pointer($context, $slot),
                    $native
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
            $slotPtr = self::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__valueDelref'),
                $slotPtr
            );
            $context->builder->store($raw, $slot);
            return $slotPtr;
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
            self::writeStringToValuePtrByAddref(
                $context,
                self::pointer($context, $slot),
                $raw
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
