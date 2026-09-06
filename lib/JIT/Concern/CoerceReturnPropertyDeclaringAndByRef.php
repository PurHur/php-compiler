<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPTypes\Type;
use PHPLLVM;

/**
 * Return coercion and by-ref callee meta (#36387).
 *
 * Property declaring-class resolution lives in {@see PropertyDeclaringClassResolve}.
 * Remaining: {@code jitDeclaredReturnTypeRequiresValue} through {@code nativeOrVarargReturnsByRef}
 * (return/ABI/type helpers) so the hub shrinks toward split-TU iterability.
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
            if (null !== $return->nestedHelperValueSlot) {
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    JIT\JitValueBox::pointer($this->context, $return->nestedHelperValueSlot)
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_VALUE === $return->type) {
                // Nullable returns use __value__*; copy merge/ternary slots into a fresh
                // return slot instead of returning an interior pointer (#8555).
                $slot = JIT\JitValueBox::alloc($this->context);
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
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
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

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            // mixed / NestedJIT scalar returns must box into `__value__*` (#20785).
            if (Variable::TYPE_NATIVE_LONG === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::writeBool(
                    $this->context,
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
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

    private function operandAt(Block $block, ?int $slot, string $context): Operand
    {
        if (null === $slot) {
            throw new \LogicException('Missing operand slot for '.$context);
        }

        return $block->getOperand($slot);
    }

    /**
     * php-types fact for a value-scope arg index (see Block::opCodeValueScopeArgs) (#36249).
     */
    private function opCodeValueArgType(OpCode $op, int $valueArgIndex): ?\PHPTypes\Type
    {
        return $op->argTypes[$valueArgIndex] ?? null;
    }

    /**
     * Primary result type for an opcode; falls back to the dest operand when unstamped (#36249).
     */
    private function opCodeResultType(Block $block, OpCode $op): ?\PHPTypes\Type
    {
        if ($op->resultType instanceof \PHPTypes\Type) {
            return $op->resultType;
        }
        if (null === $op->arg1) {
            return null;
        }
        $dest = $block->getOperand((int) $op->arg1);

        return ($dest?->type instanceof \PHPTypes\Type) ? $dest->type : null;
    }

    /**
     * Type for a specific opcode arg slot (arg1/arg2/arg3), via stamped value-scope index (#36249).
     */
    private function opCodeArgSlotType(Block $block, OpCode $op, int $argSlot): ?\PHPTypes\Type
    {
        $scopeArgs = $block->opCodeValueScopeArgs($op);
        foreach ($scopeArgs as $index => $slot) {
            if (null !== $slot && (int) $slot === $argSlot) {
                $typed = $this->opCodeValueArgType($op, (int) $index);
                if ($typed instanceof \PHPTypes\Type) {
                    return $typed;
                }
                $operand = $block->getOperand($argSlot);

                return ($operand?->type instanceof \PHPTypes\Type) ? $operand->type : null;
            }
        }

        return null;
    }

    /** Match/phi merge may leave TYPE_ASSIGN arg3 null; rhs lives in arg1 (#13092). */
    /** AssignOp peephole may leave arg2 null; lvalue lives in arg1 (#13062, #6438). */
    /** Resolve `$v` RHS from a callee formal CV before Temporary null-box fallback (#32654). */
    private function tryResolveFormalParamVariableForRhs(Block $block, Operand $rhsOperand): ?Variable
    {
        if (null === $block->func) {
            return null;
        }
        $rhsName = JIT\OperandName::resolve($rhsOperand);
        if (null !== $rhsName && '' !== $rhsName) {
            $resolvedRhs = $this->context->resolveRefAliasName($rhsName);
            if (isset($this->context->namedVariableBindings[$resolvedRhs])) {
                return $this->context->namedVariableBindings[$resolvedRhs];
            }
        }
        $rhsSlotNum = $block->slotForOperand($rhsOperand);
        if (null === $rhsSlotNum) {
            return null;
        }
        foreach ($block->func->params as $param) {
            if ($block->slotForOperand($param->result) !== $rhsSlotNum) {
                continue;
            }
            $pname = JIT\OperandName::resolve($param->result);
            if (null !== $pname && '' !== $pname) {
                $resolved = $this->context->resolveRefAliasName($pname);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    return $this->context->namedVariableBindings[$resolved];
                }
            }
            if ($this->context->hasVariableOp($param->result)) {
                return $this->context->getVariableFromOp($param->result);
            }

            return null;
        }

        return null;
    }

    private function resolveAssignRhsFromFormalParam(
        Block $block,
        Operand $rhsOperand,
        Variable $value
    ): Variable {
        $formal = $this->tryResolveFormalParamVariableForRhs($block, $rhsOperand);

        return null !== $formal ? $formal : $value;
    }

    private function binaryOpLeftSlot(OpCode $op): int
    {
        if (null !== $op->arg2) {
            return (int) $op->arg2;
        }
        if (null === $op->arg1) {
            throw new \LogicException('Missing operand slot for '.opcode_type_name($op->type).' left');
        }

        return (int) $op->arg1;
    }

    private function binaryOpLeftOperand(Block $block, OpCode $op): Operand
    {
        return $this->operandAt($block, $this->binaryOpLeftSlot($op), opcode_type_name($op->type).' left');
    }

    /** Match/ternary merge may omit arg3; phi RHS lives in arg2 (#9159, #13092). */
    private function assignRhsSlot(OpCode $op): int
    {
        if (null !== $op->arg3) {
            return (int) $op->arg3;
        }
        if (null === $op->arg2) {
            throw new \LogicException('Missing operand slot for TYPE_ASSIGN value');
        }

        return (int) $op->arg2;
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

    private function assignOperandsUsedByLiteralInclude(Block $block, OpCode $op): bool
    {
        if ([] === $block->literalIncludePaths) {
            return false;
        }
        foreach ($block->literalIncludePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (false === $code || '' === $code) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $name = JIT\OperandName::resolve($block->getOperand($slotIdx));
                if (null === $name || '' === $name) {
                    continue;
                }
                if (preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function rawTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): Type
    {
        $declared = $this->declaredTypeFromCfgParam($param);
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return Type::mixed();
        }
        if (null !== $declared && Type::TYPE_UNION === $declared->type) {
            return $declared;
        }
        // Prefer a resolved SSA result type, but never TYPE_UNKNOWN — PHPTypes leaves
        // `string $s` as UNKNOWN when the formal is read inside a loop, and treating
        // that as authoritative forced a boxed `__value__` ABI (and killed strlen/ord
        // native-string elision) (#36386).
        if (
            null !== $param->result->type
            && Type::TYPE_NULL !== $param->result->type->type
            && Type::TYPE_UNKNOWN !== $param->result->type->type
        ) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\PHPCfg\Op\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\Type\Literal) {
            // PHPTypes Type::fromDecl('mixed') mis-parses as object userType mixed (#12348 / #32728).
            if ('mixed' === strtolower($returnType->name)) {
                return Type::mixed();
            }

            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\Type\Reference && null !== $returnType->declaration) {
            $inner = $returnType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                if (is_string($inner->value) && 'mixed' === strtolower($inner->value)) {
                    return Type::mixed();
                }

                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                if ('mixed' === strtolower($inner->name)) {
                    return Type::mixed();
                }

                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\LogicException) {
            return null;
        }
    }

    private function typeIncludesNull(Type $type): bool
    {
        if (Type::TYPE_NULL === $type->type) {
            return true;
        }
        if (Type::TYPE_UNION === $type->type) {
            foreach ($type->subTypes ?? [] as $sub) {
                if ($this->typeIncludesNull($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cfgParamDeclaredTypeUsesDnfShape(\PHPCfg\Op\Expr\Param $param): bool
    {
        $declared = $param->declaredType;
        if (!$declared instanceof Op\Type) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }

        return $declared instanceof Op\Type\Nullable;
    }

    private function cfgParamIsImplicitNullable(Block $block, int $paramIdx): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIdx) {
                continue;
            }

            return isset($block->paramImplicitNullable[(int) $op->arg1]);
        }

        return false;
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $allowsNull = $this->typeIncludesNull($type);
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                $callback = 'int64';
                break;
            case Type::TYPE_DOUBLE:
                $callback = 'double';
                break;
            case Type::TYPE_BOOLEAN:
                $callback = 'bool';
                break;
            case Type::TYPE_STRING:
                $callback = '__string__*';
                break;
            case Type::TYPE_OBJECT:
                // PHPTypes Type::fromDecl('mixed') → object userType mixed (#12348 / #32728).
                // Must use boxed `__value__`, not `__object__*` (offsetGet(): mixed returned the receiver).
                if ('mixed' === strtolower((string) ($type->userType ?? ''))) {
                    $callback = '__value__';
                    break;
                }
                // NestedJIT: VM Variable returns match param ABI (#16565 / #20785).
                // Without this, UnserializeJitHelper::decode lowers as __object__* and
                // thin-AOT always-helper bridges fail module verify (peer Serialize #20773).
                if (JIT\NestedJitCompileScope::isActive() && $this->isCfgVmVariableParamType($type)) {
                    $callback = '__value__*';
                } elseif (JIT\NestedJitCompileScope::isActive() && $this->isCfgVmHashTableParamType($type)) {
                    // CompareJitHelper::hashtableSpaceship etc. (#21109).
                    $callback = '__hashtable__*';
                } else {
                    $callback = '__object__*';
                }
                break;
            case Type::TYPE_ARRAY:
                $callback = '__hashtable__*';
                break;
            case Type::TYPE_NULL:
                $callback = '__value__';
                break;
            default:
                $callback = null;
                break;
        }
        if ($allowsNull && null !== $callback && '__value__' !== $callback && '__object__*' !== $callback) {
            return '__value__*';
        }

        return $callback;
    }

    private function cfgFunctionReturnsByRef(?\PHPCfg\Func $cfgFunc): bool
    {
        return null !== $cfgFunc
            && (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** @param string ...$names logical / proxy function names */
    private function markFunctionReturnsByRef(string ...$names): void
    {
        foreach ($names as $name) {
            $lc = strtolower($name);
            if ('' !== $lc) {
                $this->context->functionReturnsRef[$lc] = true;
            }
        }
    }

    private function calleeReturnsByRef(?JIT\Call $toCall): bool
    {
        if (null === $toCall) {
            return false;
        }
        // Closure use()/bind wrappers hide the Native proxy; display name is often "{closure}"
        // while functionReturnsRef is keyed by "{closure}_N" (#34759 / re-#34717).
        if (
            $toCall instanceof JIT\Call\ClosureWithCaptures
            || $toCall instanceof JIT\Call\ClosureWithBinding
        ) {
            $toCall = JIT\ClosureBindHelper::unwrapInnerCall($toCall);
        }
        if ($toCall instanceof JIT\Call\Native || $toCall instanceof JIT\Call\Vararg) {
            return $this->nativeOrVarargReturnsByRef($toCall);
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectClosureCall) {
            foreach ($toCall->candidates as $name => $candidate) {
                if (isset($this->context->functionReturnsRef[strtolower((string) $name)])) {
                    return true;
                }
                $inner = JIT\ClosureBindHelper::unwrapInnerCall($candidate);
                if (
                    ($inner instanceof JIT\Call\Native || $inner instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($inner)
                ) {
                    return true;
                }
            }

            return false;
        }
        if ($toCall instanceof JIT\Call\NestedClosureInvoke) {
            foreach (JIT\ClosureHelper::closureCandidates($this->context) as $name => $candidate) {
                if (isset($this->context->functionReturnsRef[strtolower((string) $name)])) {
                    return true;
                }
                $inner = JIT\ClosureBindHelper::unwrapInnerCall($candidate);
                if (
                    ($inner instanceof JIT\Call\Native || $inner instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($inner)
                ) {
                    return true;
                }
            }

            return false;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            || $toCall instanceof JIT\Call\RuntimeIndirectStaticMethodCall
            || $toCall instanceof JIT\Call\RuntimeVariableStaticMethodCall
        ) {
            $candidateList = $toCall instanceof JIT\Call\RuntimeVariableStaticMethodCall
                ? $toCall->candidatesByMethodLc
                : $toCall->candidatesByClassId;
            foreach ($candidateList as $candidate) {
                if (
                    ($candidate instanceof JIT\Call\Native || $candidate instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($candidate)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when a Native/Vararg callee was registered with FLAG_RETURNS_REF.
     *
     * Prefer display-name lookup (named functions), then match the LLVM Value against
     * {@see Context::$functions} so Closure proxies keyed as `{closure}_N` still hit
     * when Native::$name is the rich `{closure}` label (#34759).
     */
    private function nativeOrVarargReturnsByRef(JIT\Call\Native|JIT\Call\Vararg $toCall): bool
    {
        if (isset($this->context->functionReturnsRef[strtolower($toCall->name)])) {
            return true;
        }
        if ($toCall instanceof JIT\Call\Native) {
            foreach ($this->context->functions as $lc => $fn) {
                if ($fn === $toCall->function) {
                    return isset($this->context->functionReturnsRef[$lc]);
                }
            }
        }

        return false;
    }
}
