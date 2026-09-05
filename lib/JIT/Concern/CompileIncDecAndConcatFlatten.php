<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Inc/dec and concat-chain flattening for JIT/AOT (#36403).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileIncDecOp} through
 * {@code tryCompileConcatChainFlatten} (value-box inc/dec, property concat/pow,
 * ephemeral concat operands, main-script publish, chain flatten). Concern trait —
 * same namespace as parent so relative Config / JIT helpers resolve.
 */
trait CompileIncDecAndConcatFlatten
{
    private function compileIncDecOp(Block $block, OpCode $op, bool $increment, bool $prefix): void
    {
        $this->maybeRefreshIncludeBindingsBeforeUse();
        $readSlot = $op->arg2 ?? $op->arg3;
        $writeSlot = $op->arg3 ?? $op->arg2;
        $readOp = $this->operandAt($block, $readSlot, 'inc/dec read');
        $writeOp = $this->operandAt($block, $writeSlot, 'inc/dec write');
        $resultOp = $this->operandAt($block, $op->arg1, 'inc/dec result');
        $read = $this->context->getVariableFromOpInScopes($readOp);
        $write = $this->context->getVariableFromOpInScopes($writeOp);
        // Array ++/-- is zend_type_error, not Analyzer compile abort (#32554 leftover of #32486).
        if (JIT\JitArrayNumericOperandGuard::guardIncDec($this->context, $read, $increment)) {
            return;
        }
        if (
            $write->isArrayAccessWritableOffset
            && null !== $write->writableArrayAccessReceiver
            && !JIT\ArrayAccessHelper::offsetGetReturnsByRefAtCompileTime(
                $this->context,
                $write->writableArrayAccessReceiver,
                null
            )
        ) {
            $className = JIT\ArrayAccessHelper::resolveContainerClassName(
                $write->writableArrayAccessReceiver,
                null
            ) ?? 'ArrayAccess';
            JIT\ArrayAccessHelper::emitIndirectModifyNotice($this->context, $className);
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
            $oneVar = new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger(1)
            );
            $newVal = $this->context->helper->binaryOp($arithOp, $read, $oneVar);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVal, true);
            }

            return;
        }
        if (
            JIT\StringOffsetHelper::isWritableCharOffsetLvalue($write, $this->context)
            || JIT\StringOffsetHelper::isWritableCharOffsetLvalue($read, $this->context)
        ) {
            JIT\StringOffsetHelper::emitIncDecError($this->context);

            return;
        }
        $literal = JIT\JitStringArg::compileTimeLiteral($read);
        if (null !== $literal) {
            $vm = new VM\Variable();
            $vm->string($literal);
            if ($increment) {
                // php-src increment_string(): empty / non-alnum → E_DEPRECATED (#29658).
                $this->emitStringIncrementDeprecationsIfNeeded($literal);
                $vm->applyIncrement();
            } else {
                // php-src decrement_function() string path (#29088, #29658).
                $this->emitStringDecrementDeprecationsIfNeeded($literal);
                $vm->applyDecrement();
            }
            $newVar = $this->jitVariableFromVmConstant($vm);
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }
        if (Variable::TYPE_STRING === $read->type) {
            // Runtime string (not a folded literal): increment_string / numeric convert (#32435).
            $str = $this->context->helper->loadValue($read);
            $slot = JIT\JitValueBox::alloc($this->context);
            $writePtr = JIT\JitValueBox::pointer($this->context, $slot);
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            JIT\JitIncDec::writeStringIncDecToValuePtr($this->context, $str, $writePtr, $increment);
            $newVar = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $slot
            );
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }
        if (null !== $read->staticPropertyGlobal) {
            $write = $this->context->getVariableFromOpInScopes($writeOp);
            $this->compileStaticPropertyIncDecOp($read, $write, $resultOp, $increment, $prefix);

            return;
        }

        if (null !== $read->magicSetReceiver && null !== $read->magicSetName) {
            $this->compileMagicPropertyIncDecOp($read, $resultOp, $increment, $prefix, $block, $op);

            return;
        }

        if (null !== $read->objectPropertySlot) {
            $this->compileObjectPropertyIncDecOp($read, $resultOp, $increment, $prefix);

            return;
        }

        if (Variable::TYPE_NULL === $read->type || ($read->isNullConstant ?? false)) {
            if ($increment) {
                $newLong = $this->context->constantFromInteger(1);
                $newVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $newLong
                );
                if (!$prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
                $this->assignOperand($writeOp, $newVar, true);
                if ($prefix) {
                    $this->assignOperand($resultOp, $newVar, true);
                }
            } else {
                // PHP 8.3+: null -- is a no-op with E_WARNING (#26378).
                $this->emitIncDecNoEffectWarning(false, 'null');
                if (!$prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
                $this->assignOperand($writeOp, $read, true);
                if ($prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
            }

            return;
        }

        if (Variable::TYPE_NATIVE_BOOL === $read->type) {
            // PHP 8.2+ zend_operators.c: bool inc/dec is a no-op (issue #7058, re-#4727).
            // PHP 8.3+: E_WARNING — will change in next major (#26378).
            $this->emitIncDecNoEffectWarning($increment, 'bool');
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $this->assignOperand($writeOp, $read, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $read, true);
            }

            return;
        }

        if (Variable::TYPE_NATIVE_DOUBLE === $read->type) {
            // zend_operators.c increment_function IS_DOUBLE: Z_DVAL_P += 1.0 (#32281).
            $cur = $this->context->helper->loadValue($read);
            $newVar = JIT\JitIncDec::nativeDoubleIncDec($this->context, $cur, $increment);
            if (!$prefix) {
                $this->assignOperand($resultOp, $read, true);
            }
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }

        if ($this->isIncDecValueBoxLvalue($read, $readOp)) {
            $this->guardIncDecResourceOperand($read, $increment, $readOp);
            // Index / string-key / value-box-key FETCH_DIM_W (#32305, #32789).
            JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $read);
            $readPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
            $cur = $this->readIncDecValueBoxLong($read, $readPtr, $increment);
            if (!$prefix) {
                // Snapshot before mutate so post-inc of float keeps float(1.5) not int(1) (#32281).
                $oldSlot = JIT\JitValueBox::alloc($this->context);
                $oldPtr = JIT\JitValueBox::pointer($this->context, $oldSlot);
                JIT\JitValueBox::copyIntoPointer($this->context, $oldPtr, $readPtr);
                $oldVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $oldSlot
                );
                $this->assignOperand($resultOp, $oldVar, true);
            }
            $write = $this->context->getVariableFromOpInScopes($writeOp);
            if ($write !== $read) {
                JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $write);
            }
            $writePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $write);
            // zend_operators.c decrement_function IS_NULL is a no-op (#32297 / #7435).
            // Compile-time TYPE_NULL already returns above; untyped `$n = null` is a value box
            // and previously readLong(null)→0 then stored int(-1).
            if (!$increment) {
                JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'dec_vbox_null_cont');
                $isNull = JIT\JitValueCompare::valueBoxIsNull($this->context, $read);
                $nullBlock = JIT\BasicBlockHelper::append($this->context, 'dec_vbox_null_noop');
                $decBlock = JIT\BasicBlockHelper::append($this->context, 'dec_vbox_numeric');
                $doneBlock = JIT\BasicBlockHelper::append($this->context, 'dec_vbox_null_done');
                $this->context->builder->branchIf($isNull, $nullBlock, $decBlock);

                $this->context->builder->positionAtEnd($nullBlock);
                $this->emitIncDecNoEffectWarning(false, 'null');
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    $writePtr
                );
                $this->context->builder->branch($doneBlock);

                $this->context->builder->positionAtEnd($decBlock);
                JIT\JitIncDec::writeValueBoxIncDec($this->context, $read, $cur, $writePtr, $increment);
                $this->context->builder->branch($doneBlock);

                $this->context->builder->positionAtEnd($doneBlock);
            } else {
                // zend_operators.c IS_DOUBLE ± 1.0; else zend_operators.h long overflow (#32281 / #29144).
                JIT\JitIncDec::writeValueBoxIncDec($this->context, $read, $cur, $writePtr, $increment);
            }
            JIT\HashTableHelper::commitDimWriteLvalue($this->context, $write);
            $this->invalidateScriptGlobalCompileTimeMetadata($write);
            if ($prefix) {
                $newVar = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $writePtr
                );
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }

        if (Variable::TYPE_NATIVE_LONG === $read->type) {
            $this->guardIncDecResourceOperand($read, $increment, $readOp);
            [$read, $write] = $this->materializeNamedNativeLongLocalForIncDec($readOp, $writeOp, $read, $write);
            $folded = $this->isNamedLocalIncDec($readOp, $writeOp)
                ? null
                : JIT\JitIncDec::tryFoldConstantLong($this->context, $read, $increment);
            if (null !== $folded) {
                if (!$prefix) {
                    $this->assignOperand($resultOp, $read, true);
                }
                $this->assignOperand($writeOp, $folded, true);
                if ($prefix) {
                    $this->assignOperand($resultOp, $folded, true);
                }

                return;
            }
            $cur = $this->context->helper->loadValue($read);
            // When the write target is an i64 alloca (KIND_VARIABLE), store the
            // incremented long directly back to the same alloca. Promoting to a
            // __value__ box disconnects it from already-compiled loop headers that
            // still read from the original i64 slot, causing infinite loops in
            // functions with for/while (#32605).
            if (Variable::KIND_VARIABLE === $write->kind
                && Variable::TYPE_NATIVE_LONG === $write->type
            ) {
                $i64 = $this->context->getTypeFromString('int64');
                $long = $this->context->builder->intCast($cur, $i64);
                $one = $i64->constInt(1, false);
                $newLong = $increment
                    ? $this->context->builder->add($long, $one)
                    : $this->context->builder->sub($long, $one);
                if (!$prefix) {
                    $oldVar = new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        $cur
                    );
                    $this->assignOperand($resultOp, $oldVar, true);
                }
                $write->free();
                $this->context->builder->store($newLong, $write->value);
                $write->addref();
                $write->compileTimeLong = null;
                if ($prefix) {
                    $newVar = new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        $newLong
                    );
                    $this->assignOperand($resultOp, $newVar, true);
                }

                return;
            }
            // Runtime long may be PHP_INT_MAX/MIN — promote via value box (#29144).
            $newVar = JIT\JitIncDec::promoteLongIntoValueBox($this->context, $cur, $increment);
            if (!$prefix) {
                $oldVar = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $cur
                );
                $this->assignOperand($resultOp, $oldVar, true);
            }
            $this->assignOperand($writeOp, $newVar, true);
            if ($prefix) {
                $this->assignOperand($resultOp, $newVar, true);
            }

            return;
        }

        // Top-level fopen() handles and other shapes that miss the buckets above (#23777 / #6396).
        $this->guardIncDecResourceOperand($read, $increment, $readOp);
        if (!$prefix) {
            $this->assignOperand($resultOp, $read, true);
        }

        $arithOp = new OpCode($increment ? OpCode::TYPE_PLUS : OpCode::TYPE_MINUS);
        $oneVar = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $this->context->constantFromInteger(1)
        );
        $newVal = $this->context->helper->binaryOp($arithOp, $read, $oneVar);
        $this->assignOperand($writeOp, $newVal, true);
        if ($prefix) {
            $this->assignOperand($resultOp, $newVal, true);
        }
    }

    /** Coerce null value-box operands to 0 before ++; decrement uses raw readLong (#7435). */
    private function readIncDecValueBoxLong(
        JIT\Variable $read,
        PHPLLVM\Value $readPtr,
        bool $increment
    ): PHPLLVM\Value {
        if (!$increment) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (JIT\Variable::TYPE_NULL === $read->type || ($read->isNullConstant ?? false)) {
            // int64 like __value__readLong — $readPtr->typeOf() is the value-box
            // POINTER type and mistypes every consumer (verifier phi mismatch).
            return $this->context->getTypeFromString('int64')->constInt(0, false);
        }
        if (!JIT\JitValueBox::isValueOperand($read)) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        $isNull = JIT\JitValueCompare::valueBoxIsNull($this->context, $read);
        $zero = $this->context->getTypeFromString('int64')->constInt(0, false);
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $readPtr
        );
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_ok');
        $nullBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_null');
        $mergeBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_null_coerce_merge');
        $this->context->builder->branchIf($isNull, $nullBlock, $okBlock);
        $this->context->builder->positionAtEnd($nullBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($okBlock);
        $this->context->builder->branch($mergeBlock);
        $this->context->builder->positionAtEnd($mergeBlock);
        $phi = $this->context->builder->phi($readLong->typeOf(), 'incdec_null_coerced');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($readLong, $okBlock);

        return $phi;
    }

    /**
     * PHP 8.3+ E_WARNING for no-op bool ++/-- or null -- (zend_operators.c, #26378).
     */
    private function emitIncDecNoEffectWarning(bool $increment, string $typeName): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Builtin\StringTriggerError::ensureLinked($this->context);
        $message = VM\Variable::incDecNoEffectWarningMessage(
            $increment ? 'Increment' : 'Decrement',
            $typeName
        );
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i32 = $this->context->getTypeFromString('int32');
        $msgPtr = $this->context->builder->pointerCast(
            $this->context->constantFromString($message),
            $i8p
        );
        $emptyFile = $this->context->builder->pointerCast(
            $this->context->constantFromString(''),
            $i8p
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(VM\ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /**
     * PHP 8.3+ E_DEPRECATED for ++ on empty / non-alphanumeric string (zend_operators.c, #29658).
     */
    private function emitStringIncrementDeprecationsIfNeeded(string $literal): void
    {
        if (is_numeric($literal)) {
            return;
        }
        if ('' !== $literal && \PHPCompiler\ext\standard\VmString::onlyAsciiAlphanumeric($literal)) {
            return;
        }
        $this->emitIncDecStringDeprecation('Increment on non-alphanumeric string is deprecated');
    }

    /**
     * PHP 8.3+ E_DEPRECATED for -- on empty or non-numeric string (#29088, #29658).
     */
    private function emitStringDecrementDeprecationsIfNeeded(string $literal): void
    {
        if ('' === $literal) {
            $this->emitIncDecStringDeprecation('Decrement on empty string is deprecated as non-numeric');

            return;
        }
        if (!is_numeric($literal)) {
            $this->emitNonNumericStringDecrementDeprecation();
        }
    }

    /**
     * PHP 8.3+ E_DEPRECATED for -- on non-numeric string (zend_operators.c, #29088).
     */
    private function emitNonNumericStringDecrementDeprecation(): void
    {
        $this->emitIncDecStringDeprecation(
            'Decrement on non-numeric string has no effect and is deprecated'
        );
    }

    /**
     * Emit a compile-time E_DEPRECATED for string ++/-- (same profile gate as #26378 / #29088).
     */
    private function emitIncDecStringDeprecation(string $message): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsIncDecNoEffectWarning()) {
            return;
        }
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        JIT\Builtin\StringTriggerError::ensureLinked($this->context);
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i32 = $this->context->getTypeFromString('int32');
        $msgPtr = $this->context->builder->pointerCast(
            $this->context->constantFromString($message),
            $i8p
        );
        $emptyFile = $this->context->builder->pointerCast(
            $this->context->constantFromString(''),
            $i8p
        );
        $this->context->builder->call(
            $this->context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($message), false),
            $i32->constInt(VM\ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /** True when ++/-- should read/write a boxed local slot via __value__* helpers. */
    private function isIncDecValueBoxLvalue(JIT\Variable $read, ?Operand $readOp): bool
    {
        if (Variable::TYPE_VALUE !== $read->type) {
            return false;
        }
        if (Variable::KIND_VARIABLE === $read->kind || $read->functionStaticGlobal) {
            return true;
        }

        // Typed locals can be KIND_VALUE rvalues bound to a scope slot (#23840).
        return $readOp instanceof Operand && $this->context->hasVariableOpInScopes($readOp);
    }

    /** Reject ++/-- on stream/dir handles (issue #6396, zend_operators.c). */
    private function guardIncDecResourceOperand(
        JIT\Variable $read,
        bool $increment,
        ?Operand $readOp = null
    ): void
    {
        if (JIT\NestedJitCompileScope::isActive()) {
            return;
        }
        $longVal = null;
        if (JIT\Variable::TYPE_NATIVE_LONG === $read->type) {
            $longVal = $this->context->helper->loadValue($read);
        } elseif (JIT\Variable::TYPE_VALUE === $read->type) {
            $readPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $read);
            $longVal = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $readPtr
            );
        }
        if (null === $longVal) {
            return;
        }
        // StreamLifecycle + StringDir: is_resource must see JitOpenStreamHandles (#23777).
        JIT\Builtin\StreamLifecycleRuntime::ensureLinked($this->context);
        JIT\Builtin\StringDir::ensureLinked($this->context);
        // Provenance proves this operand cannot be a handle — fold the registry walk to false while
        // keeping the ok-block split script-scope ++/-- requires (#23840, #23841).
        $provenNonResource = $readOp instanceof Operand
            && JIT\IncDecResourceProvenance::cannotBeResource($readOp);
        $isRes = $provenNonResource
            ? $this->context->getTypeFromString('int1')->constInt(0, false)
            : JIT\JitValueCompare::nativeLongIsResource($this->context, $longVal);
        ++self::$blockNumber;
        $suffix = (string) self::$blockNumber;
        $okBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_ok_'.$suffix);
        $errBlock = JIT\BasicBlockHelper::append($this->context, 'incdec_res_err_'.$suffix);
        $this->context->builder->branchIf($isRes, $errBlock, $okBlock);
        $this->context->builder->positionAtEnd($errBlock);
        // Catchable inside active try/catch; fatal only when uncaught (#23777).
        JIT\ExceptionBridge::emitTypeErrorAndAbort(
            $this->context,
            $increment ? 'Cannot increment resource' : 'Cannot decrement resource'
        );
        $this->context->builder->positionAtEnd($okBlock);
    }

    /** .= on object properties: concat into new string, guard readonly, store via slot (#3149). */
    private function compileObjectPropertyConcatOp(Variable $dest, Variable $left, Variable $right): void
    {
        if (null === $dest->objectPropertySlot || null === $dest->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $newVal = $this->compileConcatIntoNewString($left, $right);
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock,
            'modify',
            $this
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $dest,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        if (null !== $dest->objectPropertyDnfArms) {
            JIT\DnfParamCheck::enforcePropertyWrite(
                $this->context,
                $newVal,
                $dest->objectPropertyDnfArms
            );
        } elseif (
            null !== $dest->objectPropertyClassConstraint
            && '' !== $dest->objectPropertyClassConstraint
        ) {
            JIT\TypedPropertyClassAssignCheck::enforce(
                $this->context,
                $newVal,
                $dest->objectPropertyClassConstraint,
                $dest->objectPropertyClassName ?? '',
                $dest->objectPropertyName ?? 'property',
                $dest->objectPropertyDeclaredTypeLabel ?? $dest->objectPropertyClassConstraint
            );
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($dest, $newVal): void {
                $this->context->type->object->propertyStore(
                    $dest->objectPropertySlot,
                    $newVal,
                    $dest->objectPropertyType
                );
            }
        );
    }

    /** `$obj->prop **= n` / by-ref `$r **= n` in-place — bypass assignOperand slot strip (#35978). */
    private function compileObjectPropertyPowOp(Variable $dest, Variable $left, Variable $right): void
    {
        if (null === $dest->objectPropertySlot || null === $dest->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $pow = new \PHPCompiler\ext\standard\pow();
        $this->context->powReturnValueBox = true;
        $powResult = $pow->call($this->context, $left, $right);
        $this->context->powReturnValueBox = false;
        $newVal = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $powResult
        );
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock,
            'modify',
            $this
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $dest,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        if (null !== $dest->objectPropertyDnfArms) {
            JIT\DnfParamCheck::enforcePropertyWrite(
                $this->context,
                $newVal,
                $dest->objectPropertyDnfArms
            );
        } elseif (
            null !== $dest->objectPropertyClassConstraint
            && '' !== $dest->objectPropertyClassConstraint
        ) {
            JIT\TypedPropertyClassAssignCheck::enforce(
                $this->context,
                $newVal,
                $dest->objectPropertyClassConstraint,
                $dest->objectPropertyClassName ?? '',
                $dest->objectPropertyName ?? 'property',
                $dest->objectPropertyDeclaredTypeLabel ?? $dest->objectPropertyClassConstraint
            );
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($dest, $newVal): void {
                $this->context->type->object->propertyStore(
                    $dest->objectPropertySlot,
                    $newVal,
                    $dest->objectPropertyType
                );
            }
        );
    }

    /**
     * Entry-alloca ephemeral concat when the left operand is a named {@see Operand\Variable} (#23798).
     *
     * ConcatList chain continuations also use entry alloca (via {@see Variable::$ephemeralConcatTemp}
     * in the CONCAT handler) — KIND_VALUE-only links stack-color with dead fopen() value boxes and
     * heap-corrupt under AOT (#24024). Named Temporaries still use assignOperand on the first dead
     * link so `$s . '1'` consecutive echoes stay correct.
     */
    private function concatDeadOperandNeedsEntryAlloca(Block $block, Operand $destOp, ?Operand $leftOp): bool
    {
        if (!$leftOp instanceof Operand\Variable) {
            return false;
        }
        if ($leftOp === $destOp) {
            return false;
        }
        $name = JIT\OperandName::resolve($leftOp);
        if (null === $name || '' === $name) {
            return false;
        }
        if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return false;
        }

        return $block->hasLocallyWrittenVariableName($name);
    }

    /**
     * Store concat into a php-cfg dead Temporary (echo/call arg) with entry alloca lifetime (#23798).
     *
     * assignOperand → makeVariableFromValueOp left a bare {@see KIND_VALUE} __string__*; a second
     * concat from the same local then double-freed or corrupted the heap under AOT.
     *
     * At {main}, also publish into the script-global heap box for every CV name on the dest
     * slot — echo / ARG_SEND / var_export resolve named locals via ensureScriptGlobal and
     * would otherwise read an empty box while the ephemeral alloca held the real string
     * (#36366 p16: `$out = implode(...) . "\n"` printed checksum=0:0).
     */
    private function assignEphemeralConcatOperand(
        Block $block,
        Operand $destOp,
        Variable $left,
        Variable $right,
        \PHPLLVM\Value\Function_ $func,
        ?\PHPCfg\Operand $leftOp = null,
        ?\PHPCfg\Operand $rightOp = null
    ): void {
        $newVal = $this->compileConcatIntoNewString($left, $right, $leftOp, $rightOp);
        $destSlot = JIT\BasicBlockHelper::entryAllocaForFunction(
            $this->context,
            $func,
            $this->context->getTypeFromString('__string__*')
        );
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VARIABLE,
            $destSlot
        );
        $promoted->ephemeralConcatTemp = true;
        JIT\BasicBlockHelper::storeAtFunctionEntry(
            $this->context,
            $func,
            $this->context->type->string->pointer->constNull(),
            $destSlot
        );
        $this->context->builder->store($newVal->value, $destSlot);
        $this->context->setVariableOp($destOp, $promoted);
        $this->bindPromotedStringConcatDest($block, $destOp, $promoted);
        if (null !== ($newVal->compileTimeString ?? null)) {
            $promoted->compileTimeString = $newVal->compileTimeString;
        }
        $this->publishMainScriptNamedConcatResult($block, $destOp, $newVal);
    }

    /**
     * Seed a promoted `__string__**` from a boxed CV when the slot is still null.
     *
     * Emitted into the CONCAT block so the first `$buf .= …` after `$buf = '…'` copies the
     * box payload (via {@see __string__separate}) before {@see String_::appendInPlace}.
     * Later iterations keep the grown native pointer (#36386 / #36410).
     */
    private function seedNativeStringSlotFromValueBox(Variable $valueBox, PHPLLVM\Value $destSlot): void
    {
        $strPtrTy = $this->context->getTypeFromString('__string__*');
        $cur = $this->context->builder->load($destSlot);
        $tag = 'seedStrFromBox'.(string) spl_object_id($valueBox);
        $nullBlock = JIT\BasicBlockHelper::append($this->context, 'seed_null_'.$tag);
        $readyBlock = JIT\BasicBlockHelper::append($this->context, 'seed_ready_'.$tag);
        $isNull = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cur,
            $strPtrTy->constNull()
        );
        $this->context->builder->branchIf(
            $this->context->castToBool($isNull),
            $nullBlock,
            $readyBlock
        );

        $this->context->builder->positionAtEnd($nullBlock);
        $fromBox = JIT\JitNativeString::coerce($this->context, $valueBox);
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $this->context->helper->loadValue($fromBox)
        );
        $this->context->builder->store($owned, $destSlot);
        $this->context->builder->branch($readyBlock);

        $this->context->builder->positionAtEnd($readyBlock);
    }

    /**
     * If $valueBox holds the same __string__* as $destSlot, clear the box (delref) so
     * appendInPlace/realloc can move the buffer with a unique owner (#36386).
     */
    private function dropValueBoxStringAliasIfSame(Variable $valueBox, PHPLLVM\Value $destSlot): void
    {
        if (
            Variable::TYPE_VALUE !== $valueBox->type
            && !JIT\JitValueBox::isValueOperand($valueBox)
        ) {
            return;
        }
        $strPtrTy = $this->context->getTypeFromString('__string__*');
        $held = JIT\JitValueBox::readStringOrNull($this->context, $valueBox);
        $cur = $this->context->builder->load($destSlot);
        $tag = 'dropBoxAlias'.(string) spl_object_id($valueBox).(string) spl_object_id($destSlot);
        $dropBlock = JIT\BasicBlockHelper::append($this->context, 'drop_alias_'.$tag);
        $contBlock = JIT\BasicBlockHelper::append($this->context, 'drop_alias_cont_'.$tag);
        $same = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $held, $cur);
        $nonNull = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $cur,
            $strPtrTy->constNull()
        );
        $shouldDrop = $this->context->builder->bitwiseAnd(
            $this->context->castToBool($same),
            $this->context->castToBool($nonNull)
        );
        $this->context->builder->branchIf($shouldDrop, $dropBlock, $contBlock);
        $this->context->builder->positionAtEnd($dropBlock);
        // addref before writeNull so a false-share at rc=1 (box+destSlot without a
        // matching addref on publish) is not freed out from under destSlot (#36386).
        $this->context->refcount->addref($cur);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::valuePtrFromVariable($this->context, $valueBox)
        );
        $this->context->builder->branch($contBlock);
        $this->context->builder->positionAtEnd($contBlock);
    }

    /**
     * Same as {@see dropValueBoxStringAliasIfSame} for {main} script-globals that
     * publishMainScriptNamedConcatResult keeps in sync with the promoted slot.
     */
    private function dropMainScriptStringAliasIfSame(
        Block $block,
        Operand $destOp,
        PHPLLVM\Value $destSlot
    ): void {
        if (!$block->isMainScript()) {
            return;
        }
        $names = [];
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $names[$destName] = true;
        }
        $slot = $block->slotForOperand($destOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $name = (string) $name;
            if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $destOp, $block)) {
                continue;
            }
            $sg = $this->context->ensureScriptGlobal($name);
            $this->dropValueBoxStringAliasIfSame($sg, $destSlot);
        }
    }

    /**
     * {main} CV reads go through ensureScriptGlobal() boxes (#23842). Ephemeral /
     * promoted CONCAT stores only an __string__* alloca — without this write, echo and
     * ARG_SEND of `$b = $a . "x"` at top level see NULL / empty (#36366).
     */
    private function publishMainScriptNamedConcatResult(
        Block $block,
        Operand $destOp,
        Variable $stringVal
    ): void {
        if (!$block->isMainScript()) {
            return;
        }
        $names = [];
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $names[$destName] = true;
        }
        $slot = $block->slotForOperand($destOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $name = (string) $name;
            if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $destOp, $block)) {
                continue;
            }
            $sg = $this->context->ensureScriptGlobal($name);
            JIT\JitValueBox::assignToPointer(
                $this->context,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $sg),
                $stringVal
            );
            JIT\JitValueBox::publishAfterWrite(
                $this->context,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $sg)
            );
            if (null !== ($stringVal->compileTimeString ?? null)) {
                $sg->compileTimeString = $stringVal->compileTimeString;
            }
            $sg->isNullConstant = false;
            $this->context->bindVariableByName($name, $sg);
            JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $sg);
        }
    }

    /**
     * Defer single-use CONCAT temps and flatten in-place `.=` into sequential appends (#36386).
     *
     * php-src: Zend/zend_operators.c ZEND_ASSIGN_CONCAT / zend_string_extend /
     * Zend/zend_string.h zend_print_long_to_buf (via appendInPlaceLong).
     */
    private function tryCompileConcatChainFlatten(
        PHPLLVM\Value $func,
        Block $block,
        OpCode $op,
        int $opIndex
    ): bool {
        if (null === $op->arg1 || null === $op->arg2 || null === $op->arg3) {
            return false;
        }
        $destOp = $block->getOperand($op->arg1);
        $leftOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
        $rightOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3);
        if (null === $destOp || null === $leftOp || null === $rightOp) {
            return false;
        }
        $destSlotNum = (int) $op->arg1;
        if (isset($this->context->coalesceMergeSlotOperands[$destSlotNum])) {
            return false;
        }
        if ($this->context->coalesceAssignTargets->contains($destOp)) {
            return false;
        }

        $leftLeaves = $this->consumeConcatPendingLeaves($leftOp);
        $rightLeaves = $this->consumeConcatPendingLeaves($rightOp);
        $merged = array_merge($leftLeaves, $rightLeaves);
        $hadPending = \count($merged) > 2
            || \count($leftLeaves) > 1
            || \count($rightLeaves) > 1;
        $inPlace = (int) $op->arg1 === (int) $op->arg2;

        if ($inPlace) {
            if (!$this->context->hasVariableOp($destOp) && !$this->context->hasVariableOp($leftOp)) {
                // Re-store leaves so a later materialize path can still see them.
                if ($hadPending) {
                    $this->concatPendingLeaves[spl_object_id($rightOp)] = $rightLeaves;
                    if (\count($leftLeaves) > 1) {
                        $this->concatPendingLeaves[spl_object_id($leftOp)] = $leftLeaves;
                    }
                }

                return false;
            }
            $result = $this->context->hasVariableOp($destOp)
                ? $this->context->getVariableFromOp($destOp)
                : $this->context->getVariableFromOp($leftOp);
            if (
                null !== $result->writableHt
                || null !== $result->objectPropertySlot
                || null !== $result->staticPropertyGlobal
                || JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
            ) {
                if ($hadPending) {
                    $newVal = $this->materializeConcatLeaves($merged, $block);
                    $this->assignOperand($destOp, $newVal, true);
                    $this->maybeRefreshIncludeBindingsBeforeUse();

                    return true;
                }

                return false;
            }
            // Simple `$a .= $b` (one leaf each, no pending): keep the existing path.
            if (!$hadPending && 2 === \count($merged)) {
                return false;
            }
            $dest = $this->ensureNativeStringSlotForConcatFlatten($func, $block, $op, $destOp, $result);
            if (null === $dest) {
                if ($hadPending) {
                    $newVal = $this->materializeConcatLeaves($merged, $block);
                    $this->assignOperand($destOp, $newVal, true);
                    $this->maybeRefreshIncludeBindingsBeforeUse();

                    return true;
                }

                return false;
            }
            $this->dropMainScriptStringAliasIfSame($block, $destOp, $dest->value);
            if (
                Variable::TYPE_VALUE === $result->type
                || JIT\JitValueBox::isValueOperand($result)
            ) {
                $this->dropValueBoxStringAliasIfSame($result, $dest->value);
            }
            foreach ($merged as $leafOp) {
                // Skip the in-place left CV itself when it appears as the first leaf
                // (CONCAT($s,$s,$rhs) → leaves are [$s, ...rhs]); appending $s onto $s
                // would alias the buffer during realloc.
                if ($leafOp === $destOp || $leafOp === $leftOp) {
                    if ($leafOp === $merged[0] && $inPlace) {
                        continue;
                    }
                }
                $this->appendConcatLeafToNativeString($dest, $leafOp, $block);
            }
            $dest->compileTimeString = null;
            $this->markScopeVariableAssignedIfTracked($destOp, $dest);
            $this->maybeRefreshIncludeBindingsBeforeUse();

            return true;
        }

        // Non-in-place: defer single-use temps that only feed another CONCAT.
        if ($this->isSingleUseConcatChainTemp($block, $destOp, (int) $op->arg1, $opIndex)) {
            $this->concatPendingLeaves[spl_object_id($destOp)] = $merged;

            return true;
        }

        // Consumed pending leaves but cannot defer — materialize into a fresh string.
        if ($hadPending) {
            $newVal = $this->materializeConcatLeaves($merged, $block);
            $this->assignOperand($destOp, $newVal, true);
            $this->publishMainScriptNamedConcatResult($block, $destOp, $newVal);
            $this->markScopeVariableAssignedIfTracked($destOp, $newVal);
            $this->maybeRefreshIncludeBindingsBeforeUse();

            return true;
        }

        return false;
    }

}
