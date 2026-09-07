<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Return coercion and ABI helpers (#36387).
 *
 * Property declaring-class resolution lives in {@see PropertyDeclaringClassResolve}.
 * CFG param/return typing + callee by-ref meta live in {@see CfgParamReturnTypeAndCalleeByRef}.
 * Opcode arg-slot typing + assign/binary RHS live in {@see OpCodeArgSlotAndAssignRhs}.
 * Remaining here: {@code jitDeclaredReturnTypeRequiresValue} through
 * {@code isValueStructLlvmType} (return coerce / void-default ABI).
 */
trait CoerceReturnPropertyDeclaringAndByRef
{
    /** Mirror VM declaredReturnTypeRequiresValue for RETURN_VOID epilogues (#26485). */
    private function jitDeclaredReturnTypeRequiresValue(Block $block): bool
    {
        if ($block->returnTypeMixed || $block->returnTypeStatic) {
            return true;
        }
        if (JIT\ClassReturnCheck::generatorSkipsBodyReturnCheck($block)) {
            return false;
        }
        if (null !== $block->returnDnfConstraints) {
            return true;
        }
        if (null !== $block->returnClassConstraint) {
            return true;
        }

        return null !== $block->returnTypeConstraint;
    }

    private function jitReturnTypeCallableName(Block $block): ?string
    {
        $func = $block->func;
        if (null === $func && (null === $block->closureRichDisplayName || '' === $block->closureRichDisplayName)) {
            return null;
        }

        return VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($func, null, $block);
    }

    private function coerceReturnValue(Variable $return, PHPLLVM\Value $retval, ?string $expected): PHPLLVM\Value
    {
        // Overflowable native-long ±/×/`/` must box before ABI coerce — writeLong of the
        // i64 phi would return 0 on the promote arm (#36386 / leftover of #37051).
        if (
            null !== $return->longArithOverflowFlag
            && (null !== $return->longArithOverflowDoubleSlot || null !== $return->longArithOverflowPromoted)
            && Variable::TYPE_NATIVE_LONG === $return->type
            && (
                '__value__*' === $expected
                || '__value__' === $expected
                || null === $expected
            )
        ) {
            $return = JIT\JitLongArithOverflow::materializeOverflowableNativeLong($this->context, $return);
            $retval = $this->context->helper->loadValue($return);
        }
        if ('__object__*' === $expected && Variable::TYPE_OBJECT === $return->type) {
            return $retval;
        }
        // Untyped CFG may box Script into __value__; M5 Parser::parse ABI wants __object__* (#27426).
        if ('__object__*' === $expected && Variable::TYPE_VALUE === $return->type) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        }
        if ('__value__*' === $expected) {
            // Heap boxes only — stack alloca's dangle after ret (#36382 / #8555 leftovers).
            if (null !== $return->nestedHelperValueSlot) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    JIT\JitValueBox::pointer($this->context, $return->nestedHelperValueSlot)
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_VALUE === $return->type) {
                // Nullable returns use __value__*; copy merge/ternary / property slots into a
                // heap return box instead of returning a stack interior pointer (#8555 / #36382).
                $slot = JIT\JitValueBox::allocHeap($this->context);
                JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__value__*')->constNull();
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            // mixed / NestedJIT scalar returns must box into `__value__*` (#20785).
            if (Variable::TYPE_NATIVE_LONG === $return->type) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                JIT\JitValueBox::writeBool(
                    $this->context,
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::allocHeap($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }

            return $this->context->getTypeFromString('__value__*')->constNull();
        }
        if ('__value__' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                if (Variable::KIND_VARIABLE === $return->kind) {
                    return $this->context->builder->load($return->value);
                }
                if ('__value__*' === $this->context->getStringFromType($retval->typeOf())) {
                    return $this->context->builder->load($retval);
                }

                return $retval;
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->loadNullValueStruct();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_HASHTABLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::writeBool(
                    $this->context,
                    $slot,
                    $this->context->builder->truncOrBitCast(
                        $retval,
                        $this->context->getTypeFromString('int1')
                    )
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_LONG === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (0 !== ($return->type & Variable::IS_NATIVE_ARRAY)) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $ht = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $return);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $ht
                );

                return $this->context->builder->load($slot);
            }

            return $this->loadNullValueStruct();
        }
        if (null === $expected || Variable::TYPE_VALUE !== $return->type) {
            if ('bool' === $expected && Variable::TYPE_NATIVE_BOOL === $return->type) {
                return $this->context->builder->truncOrBitCast(
                    $retval,
                    $this->context->getTypeFromString('int1')
                );
            }
            if (
                ('int64' === $expected || 'long long' === $expected)
                && Variable::TYPE_NATIVE_LONG === $return->type
            ) {
                $i64 = $this->context->getTypeFromString('int64');
                if ($retval->typeOf() !== $i64) {
                    return $this->context->builder->zext($retval, $i64);
                }

                return $retval;
            }
            if ('int32' === $expected && Variable::TYPE_NATIVE_LONG === $return->type) {
                return $this->context->builder->trunc(
                    $retval,
                    $this->context->getTypeFromString('int32')
                );
            }
            if ('__value__' === $expected && Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if ('__string__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__string__*')->constNull();
            }
            if ('__hashtable__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            }
            if ('__hashtable__*' === $expected && Variable::TYPE_HASHTABLE === $return->type) {
                $htPtr = $this->context->getTypeFromString('__hashtable__*');
                if ($retval->typeOf() !== $htPtr) {
                    return $this->context->builder->bitcast($retval, $htPtr);
                }

                return $retval;
            }
            if ('__hashtable__*' === $expected && 0 !== ($return->type & Variable::IS_NATIVE_ARRAY)) {
                return JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $return);
            }
            if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
                return JIT\JitValueBox::readStringOrNull($this->context, $return);
            }

            return $retval;
        }
        if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
            return JIT\JitValueBox::readStringOrNull($this->context, $return);
        }
        // KIND_VALUE may already hold `__value__*` (e.g. loaded `static ?\FFI $ffi`).
        // Never `store` that pointer into an alloca `__value__` — module verify fails
        // with "Stored value type does not match pointer operand type" (#24429 sockets).
        $valuePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $return);
        if ('long long' === $expected || 'int64' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if ('double' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readDouble'),
                $valuePtr
            );
        }
        if ('bool' === $expected) {
            return $this->context->builder->truncOrBitCast(
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                ),
                $this->context->getTypeFromString('int1')
            );
        }
        if ('__object__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }
        if ('__hashtable__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $valuePtr
            );
        }

        return $retval;
    }

    private function alignRetvalToLlvmFnReturn(PHPLLVM\Value $retval, PHPLLVM\Value $func): PHPLLVM\Value
    {
        $want = null;
        $sig = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
        if (null !== $sig) {
            $want = $sig->getReturnType();
        }
        if (null === $want && null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected && 'void' !== $expected) {
                $want = $this->context->getTypeFromString($expected);
            }
        }
        if (null === $want) {
            return $retval;
        }
        $have = $retval->typeOf();
        if ($want === $have) {
            return $retval;
        }
        $wantStr = $this->context->getStringFromType($want);
        $haveStr = $this->context->getStringFromType($have);
        if (('int1' === $wantStr || 'bool' === $wantStr) && ('int64' === $haveStr || 'long long' === $haveStr || 'int32' === $haveStr)) {
            return $this->context->builder->truncOrBitCast($retval, $want);
        }
        if ('int8' === $haveStr && ('int32' === $wantStr || 'int64' === $wantStr || 'long long' === $wantStr)) {
            return $this->context->builder->zext($retval, $want);
        }
        if ('int32' === $wantStr && ('int64' === $haveStr || 'long long' === $haveStr)) {
            return $this->context->builder->trunc($retval, $want);
        }
        if (('int64' === $wantStr || 'long long' === $wantStr) && ('int32' === $haveStr || 'int1' === $haveStr)) {
            return $this->context->builder->zext($retval, $want);
        }
        if ('__hashtable__*' === $wantStr && '__object__*' === $haveStr) {
            return $this->context->builder->bitcast($retval, $want);
        }
        if ('__object__*' === $wantStr && '__hashtable__*' === $haveStr) {
            return $this->context->builder->bitcast($retval, $want);
        }
        if ('__value__' === $wantStr && '__value__*' === $haveStr) {
            return $this->context->builder->load($retval);
        }
        // M5 Parser::parse: body may still emit __value__ while signature is __object__* (#27426).
        if ('__object__*' === $wantStr && '__value__' === $haveStr) {
            $tmp = $this->context->builder->alloca($have);
            $this->context->builder->store($retval, $tmp);

            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $tmp
            );
        }
        if ('__object__*' === $wantStr && '__value__*' === $haveStr) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $retval
            );
        }
        if ('__value__' === $wantStr && ('int64' === $haveStr || 'long long' === $haveStr)) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                JIT\JitValueBox::pointer($this->context, $slot),
                $retval
            );

            return $this->context->builder->load($slot);
        }
        // NestedJIT sprintf → number_format: a return site may still hold i64 while the
        // LLVM signature is `__string__*` (Slim/Nyholm Uri throw path, #36382).
        if ('__string__*' === $wantStr && ('int64' === $haveStr || 'long long' === $haveStr || 'int32' === $haveStr)) {
            $asI64 = $retval;
            if ('int32' === $haveStr || 'int1' === $haveStr) {
                $asI64 = $this->context->builder->zext(
                    $retval,
                    $this->context->getTypeFromString('int64')
                );
            }

            return $this->context->builder->call(
                $this->context->lookupFunction('__string__fromLong'),
                $asI64
            );
        }
        if ('__string__*' === $wantStr && ('__value__*' === $haveStr || '__value__' === $haveStr)) {
            if ('__value__' === $haveStr) {
                $tmp = $this->context->builder->alloca($have);
                $this->context->builder->store($retval, $tmp);

                return $this->context->builder->call(
                    $this->context->lookupFunction('__value__readString'),
                    $tmp
                );
            }

            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readString'),
                $retval
            );
        }
        if (\PHPLLVM\Type::KIND_INTEGER === $want->getKind() && \PHPLLVM\Type::KIND_INTEGER === $have->getKind()) {
            return $this->context->builder->truncOrBitCast($retval, $want);
        }
        if (\PHPLLVM\Type::KIND_POINTER === $want->getKind() && \PHPLLVM\Type::KIND_POINTER === $have->getKind()) {
            return $this->context->builder->bitcast($retval, $want);
        }

        return $retval;
    }

    private function isVoidCfgFunction(Block $block): bool
    {
        return 'void' === $this->cfgFunctionReturnCallbackType($block->func);
    }

    private function isVoidLlvmFunction(PHPLLVM\Value $func): bool
    {
        return JIT\BasicBlockHelper::isVoidLlvmFunctionValue($func);
    }

    private function defaultLlvmReturnValue(PHPLLVM\Value $func): PHPLLVM\Value
    {
        if (null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected) {
                return $this->defaultLlvmReturnValueForCallbackType($expected, $func);
            }
        }
        $fnType = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
        if (null === $fnType) {
            return $this->context->constantFromInteger(0);
        }
        $llvmReturn = $this->context->getStringFromType($fnType->getReturnType());
        if ('unknown' === $llvmReturn && \PHPLLVM\Type::KIND_STRUCT === $fnType->getReturnType()->getKind()) {
            $llvmReturn = '__value__';
        }

        return $this->defaultLlvmReturnValueForCallbackType($llvmReturn, $func);
    }

    private function emitSelfHostStubReturn(string $callbackType, PHPLLVM\Value $func, ?int $longReturn = null): void
    {
        if ('void' === $callbackType) {
            $this->context->builder->returnVoid();
            return;
        }
        $this->context->builder->returnValue(
            $this->defaultLlvmReturnValueForCallbackType($callbackType, $func, $longReturn)
        );
    }

    private function defaultLlvmReturnValueForCallbackType(
        string $callbackType,
        PHPLLVM\Value $func,
        ?int $longReturn = null
    ): PHPLLVM\Value {
        switch ($callbackType) {
            case 'long long':
            case 'int64':
                return $this->context->getTypeFromString('int64')->constInt($longReturn ?? 0, false);
            case 'double':
                return $this->context->getTypeFromString('double')->constReal(0.0);
            case 'bool':
            case 'int1':
                return $this->context->getTypeFromString('bool')->constInt(0, false);
            case '__string__*':
                return $this->context->getTypeFromString('__string__*')->constNull();
            case '__object__*':
                return $this->context->getTypeFromString('__object__*')->constNull();
            case '__hashtable__*':
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            case '__value__*':
                return $this->context->getTypeFromString('__value__*')->constNull();
            case '__value__':
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\JitValueBox::pointer($this->context, $slot)
                );
                return $this->context->builder->load($slot);
            default:
                $fnType = $func->typeOf();
                if ($fnType instanceof \PHPLLVM\Type\Function_) {
                    $returnType = $fnType->getReturnType();
                    if ($this->isValueStructLlvmType($returnType)) {
                        return $this->loadNullValueStruct();
                    }
                    if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                        return $returnType->constNull();
                    }
                    if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                        return $returnType->constInt(0, false);
                    }
                }
                return $this->context->constantFromInteger(0);
        }
    }

    private function loadNullValueStruct(): PHPLLVM\Value
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return $this->context->builder->load($slot);
    }

    private function isValueStructLlvmType(PHPLLVM\Type $type): bool
    {
        return $type->toString() === $this->context->getTypeFromString('__value__')->toString();
    }
}
