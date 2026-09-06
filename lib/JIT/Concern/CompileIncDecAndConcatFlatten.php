<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Inc/dec opcode lowering for JIT/AOT (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileIncDecOp} and value-box / string /
 * resource helpers. Object-property `.=` / `**=` and concat-chain flatten live in
 * {@see CompileObjectPropertyConcatPowAndFlatten}. Concern trait — same namespace as parent
 * so relative Config / JIT helpers resolve.
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

}
