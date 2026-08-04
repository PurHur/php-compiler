<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper as JitGeneratorHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT generator iterator / resume-call LLVM helpers — PHP SSOT (#10105, php-in-PHP).
 *
 * php-src: Zend/zend_generators.c — generator create/resume/close
 */
final class GeneratorIteratorJitHelper
{
    /**
     * On resume: if Generator::throw() set has_pending_throw, inject into the active
     * try/catch dispatch (or pend for the caller) — mirrors FiberHelperLlvm (#27518).
     *
     * @return \PHPLLVM\BasicBlock block to continue resume-prefix lowering from
     */
    public static function emitInjectPendingThrow(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Value $stateParam,
        \PHPLLVM\BasicBlock $caseBlock
    ): \PHPLLVM\BasicBlock {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $normalEntry = $func->appendBasicBlock('gen_resume_normal');
        $throwEntry = $func->appendBasicBlock('gen_resume_throw_inject');
        $context->builder->positionAtEnd($caseBlock);
        $hasPending = $context->builder->load(
            $context->builder->structGep($stateParam, $map['has_pending_throw'])
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasPending, $i1->constInt(0, false)),
            $throwEntry,
            $normalEntry
        );
        $context->builder->positionAtEnd($throwEntry);
        \PHPCompiler\JIT\Builtin\JitThrow::registerDeclarations($context);
        \PHPCompiler\JIT\Builtin\JitThrow::ensureLinked($context);
        $pendingField = $context->builder->structGep($stateParam, $map['pending_throw']);
        $excObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::pointer($context, $pendingField)
        );
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excObj);
        $context->builder->store(
            $i1->constInt(0, false),
            $context->builder->structGep($stateParam, $map['has_pending_throw'])
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $pendingField)
        );
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        $branchedToDispatch = false;
        if (null !== $handler && null !== $handler->dispatchBb) {
            // While lowering this resume function, dispatchBb was just appended to $func
            // — branch without sameLlvmFunction (wrapper identity can miss, #27518).
            if ($context->compilingGeneratorResume) {
                $context->builder->branch($handler->dispatchBb);
                $branchedToDispatch = true;
            } else {
                $dispatchParent = $handler->dispatchBb->getParent();
                if (
                    $dispatchParent instanceof \PHPLLVM\Value\Function_
                    && TryCatchHelper::sameLlvmFunction($func, $dispatchParent)
                ) {
                    $context->builder->branch($handler->dispatchBb);
                    $branchedToDispatch = true;
                }
            }
        }
        if (!$branchedToDispatch) {
            // No in-generator catch: close and return so the caller observes throw-pending
            // (Zend zend_generator_throw → GeneratorUncaughtThrow).
            $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
            $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
            $context->builder->returnValue($i64->constInt(0, false));
        }
        // Always leave the builder on the open normal path — callers (pending_send) must
        // not append after throwEntry's terminator (#27518).
        $context->builder->positionAtEnd($normalEntry);

        return $normalEntry;
    }

    /**
     * On resume past a yield: copy pending_send into the yield expression result (or
     * discard when the yield has no receive slot) — Zend zend_generator_resume (#26819).
     *
     * @return \PHPLLVM\BasicBlock block to continue resume-prefix lowering from
     */
    public static function emitInjectPendingSend(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Block $block,
        OpCode $yieldOp,
        Value $stateParam,
        \PHPLLVM\BasicBlock $entryBlock
    ): \PHPLLVM\BasicBlock {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $hasPendingPtr = $context->builder->structGep($stateParam, $map['has_pending_send']);
        if (null === $yieldOp->arg1) {
            // Plain `yield expr` — discard the sent value (VM #18108 / #23712).
            $context->builder->positionAtEnd($entryBlock);
            $context->builder->store($i1->constInt(0, false), $hasPendingPtr);

            return $entryBlock;
        }
        $resultOp = $block->getOperand($yieldOp->arg1);
        if (!$context->hasVariableOpInScopes($resultOp)) {
            $context->makeVariableFromOp($func, $entryBlock, $block, $resultOp);
        }
        $resultVar = $context->getVariableFromOp($resultOp);
        $pendingField = $context->builder->structGep($stateParam, $map['pending_send']);
        $context->builder->positionAtEnd($entryBlock);
        $hasPending = $context->builder->load($hasPendingPtr);
        $parent = $context->builder->getInsertBlock()->getParent();
        $copyBb = $parent->appendBasicBlock('gen_inject_send');
        $nullBb = $parent->appendBasicBlock('gen_inject_null');
        $doneBb = $parent->appendBasicBlock('gen_inject_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasPending, $i1->constInt(0, false)),
            $copyBb,
            $nullBb
        );

        $context->builder->positionAtEnd($copyBb);
        JitValueBox::copyFromPointer(
            $context,
            JitValueBox::pointer($context, $resultVar->value),
            $pendingField
        );
        $context->builder->store($i1->constInt(0, false), $hasPendingPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultVar->value)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $doneBb;
    }

    public static function emitYieldPoint(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $op,
        Value $stateParam,
        int $nextResumeIp
    ): void {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');

        $valueOp = null !== $op->arg2 ? $block->getOperand($op->arg2) : null;
        $keyOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
        $valField = $context->builder->structGep($stateParam, $map['current_value']);
        $keyField = $context->builder->structGep($stateParam, $map['current_key']);

        if (null !== $valueOp) {
            $valVar = $context->getVariableFromOp($valueOp);
            $jit->assignValueToGeneratorField($valField, $valVar, $valueOp);
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $valField)
            );
        }

        if (null !== $keyOp) {
            $keyVar = $context->getVariableFromOp($keyOp);
            $jit->assignValueToGeneratorField($keyField, $keyVar, $keyOp);
            self::emitBumpAutoKeyFromExplicitKey($context, $stateParam, $keyField);
        } else {
            $autoKey = $context->builder->load($context->builder->structGep($stateParam, $map['auto_key']));
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $keyField),
                $context->builder->truncOrBitCast($autoKey, $context->getTypeFromString('int64'))
            );
            $context->builder->store(
                $context->builder->add($autoKey, $sizeT->constInt(1, false)),
                $context->builder->structGep($stateParam, $map['auto_key'])
            );
        }

        $context->builder->store(
            $i1->constInt(1, false),
            $context->builder->structGep($stateParam, $map['has_current'])
        );
        $context->builder->store(
            $sizeT->constInt($nextResumeIp, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(1, false));
    }

    /**
     * Zend zend_generators.c — bump auto_key after an explicit IS_LONG yield key (#22343).
     */
    private static function emitBumpAutoKeyFromExplicitKey(
        Context $context,
        Value $stateParam,
        Value $keyField
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        $checkBb = $fn->appendBasicBlock('gen_autokey_check');
        $bumpBb = $fn->appendBasicBlock('gen_autokey_bump');
        $doneBb = $fn->appendBasicBlock('gen_autokey_done');

        $keyPtr = JitValueBox::pointer($context, $keyField);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyField, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        // Also accept VM TYPE_INTEGER tag (1) for generator fields written from VM-shaped values.
        $isVmInt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_INTEGER & 0x7f, false)
        );
        $isIntKey = $context->builder->or($isLong, $isVmInt);
        $context->builder->branchIf($isIntKey, $checkBb, $doneBb);

        $context->builder->positionAtEnd($checkBb);
        $k = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $autoKeyPtr = $context->builder->structGep($stateParam, $map['auto_key']);
        $autoKey = $context->builder->load($autoKeyPtr);
        $autoAsI64 = $context->builder->truncOrBitCast($autoKey, $i64);
        $kGte = $context->builder->icmp(Builder::INT_SGE, $k, $autoAsI64);
        $context->builder->branchIf($kGte, $bumpBb, $doneBb);

        $context->builder->positionAtEnd($bumpBb);
        $next = $context->builder->add($k, $i64->constInt(1, false));
        $context->builder->store(
            $context->builder->truncOrBitCast($next, $sizeT),
            $autoKeyPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    public static function compileIterValid(Context $context, Variable $gen): Value
    {
        if (null === $gen->generatorStatePtr || null === $gen->generatorResumeName) {
            throw new \LogicException('foreach requires a Generator value in this compiler build');
        }
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $doneField = $context->builder->structGep($state, $map['done']);
        $hasCurrentField = $context->builder->structGep($state, $map['has_current']);
        $needsAdvanceField = $context->builder->structGep($state, $map['foreach_needs_advance']);
        $fn = $context->builder->getInsertBlock()->getParent();
        $doneBb = $fn->appendBasicBlock('gen_iter_done');
        $checkPosBb = $fn->appendBasicBlock('gen_iter_check_pos');
        $useCurrentBb = $fn->appendBasicBlock('gen_iter_use_current');
        $resumeBb = $fn->appendBasicBlock('gen_iter_resume');
        $mergeBb = $fn->appendBasicBlock('gen_iter_merge');
        $resumeFn = $context->functions[strtolower($gen->generatorResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }

        $context->builder->branchIf($context->builder->load($doneField), $doneBb, $checkPosBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->branch($mergeBb);

        // Already on a yield and first VALID after RESET — report true without advancing (#23713).
        $context->builder->positionAtEnd($checkPosBb);
        $hasCurrent = $context->builder->load($hasCurrentField);
        $needsAdvance = $context->builder->load($needsAdvanceField);
        $useCurrent = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false)),
            $context->builder->icmp(Builder::INT_EQ, $needsAdvance, $i1->constInt(0, false))
        );
        $context->builder->branchIf($useCurrent, $useCurrentBb, $resumeBb);

        $context->builder->positionAtEnd($useCurrentBb);
        $context->builder->store($i1->constInt(1, false), $needsAdvanceField);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($resumeBb);
        $loopHead = $resumeBb;
        // Clear AT_FIRST_YIELD like zend_generator_resume.
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['at_first_yield']));
        $yielded = $context->builder->call($resumeFn, $state);
        $context->builder->store($i1->constInt(1, false), $needsAdvanceField);
        $hasYield = $context->builder->icmp(Builder::INT_NE, $yielded, $i64->constInt(0, false));
        $afterResume = $fn->appendBasicBlock('gen_iter_after_resume');
        $context->builder->branchIf($hasYield, $mergeBb, $afterResume);

        $context->builder->positionAtEnd($afterResume);
        $context->builder->branchIf($context->builder->load($doneField), $doneBb, $loopHead);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $doneBb);
        $phi->addIncoming($i1->constInt(1, false), $useCurrentBb);
        $phi->addIncoming($i1->constInt(1, false), $resumeBb);

        return $phi;
    }

    public static function compileIterKey(Context $context, Variable $gen): Variable
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $gen);
        $keyField = $context->builder->structGep(
            $statePtr,
            $context->structFieldMap['__generator_state__']['current_key']
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $keyField);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterValue(Context $context, Variable $gen): Variable
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $gen);
        $valField = $context->builder->structGep(
            $statePtr,
            $context->structFieldMap['__generator_state__']['current_value']
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $valField);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterValueByRef(
        Context $context,
        Variable $gen,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        if (!GeneratorJitHelper::generatorYieldsByReference($context, $gen)) {
            self::emitForeachGeneratorByRefError($context, $jit);
            $slot = JitValueBox::alloc($context);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }
        $statePtr = self::loadStateFromGeneratorObject($context, $gen);
        $valField = $context->builder->structGep(
            $statePtr,
            $context->structFieldMap['__generator_state__']['current_value']
        );
        $var = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        $var->valueBoxAliasPtr = JitValueBox::pointer($context, $valField);
        $var->borrowedValueEntry = true;

        return $var;
    }

    public static function compileIterReset(Context $context, Variable $gen): void
    {
        if (null === $gen->generatorStatePtr) {
            return;
        }
        // Foreach ITER_RESET: reject closed/advanced; do not open unstarted gens (#23713).
        self::compileAssertGeneratorIterableForRewind($context, $gen);
        $statePtr = self::loadStateFromGeneratorObject($context, $gen);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store(
            $i1->constInt(0, false),
            $context->builder->structGep($statePtr, $map['foreach_needs_advance'])
        );
    }

    /**
     * Zend ext/spl/php_spl.c — iterator_to_array()/iterator_count() on started/closed Generator (#18582).
     *
     * Rewind is allowed while ZEND_GENERATOR_AT_FIRST_YIELD (#23713); only advanced/closed gens fail.
     */
    public static function compileAssertGeneratorIterableForRewind(Context $context, Variable $gen): void
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $gen);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        $checkStarted = $fn->appendBasicBlock('gen_assert_rewind_check_started');
        $failClosed = $fn->appendBasicBlock('gen_assert_rewind_fail_closed');
        $failStarted = $fn->appendBasicBlock('gen_assert_rewind_fail_started');
        $ok = $fn->appendBasicBlock('gen_assert_rewind_ok');
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $context->builder->branchIf($done, $failClosed, $checkStarted);
        $context->builder->positionAtEnd($checkStarted);
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $atFirst = $context->builder->load($context->builder->structGep($statePtr, $map['at_first_yield']));
        $opened = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $resumeIp, $zero),
            $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false))
        );
        $advancedPastFirst = $context->builder->and(
            $opened,
            $context->builder->icmp(Builder::INT_EQ, $atFirst, $i1->constInt(0, false))
        );
        $context->builder->branchIf($advancedPastFirst, $failStarted, $ok);
        $context->builder->positionAtEnd($failClosed);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'Exception',
            GeneratorState::CLOSED_TRAVERSE_ERROR
        );
        $context->builder->positionAtEnd($failStarted);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'Exception',
            GeneratorState::REWIND_ALREADY_RUN_ERROR
        );
        $context->builder->positionAtEnd($ok);
    }

    public static function loadStateFromGeneratorObject(Context $context, Variable $genVar): Value
    {
        if (null !== $genVar->generatorStatePtr) {
            return $genVar->generatorStatePtr;
        }
        // Normalize for the property load only — do not rebind $genVar. Caching the
        // state pointer on a discarded TYPE_OBJECT copy left callers with a null
        // $gen->generatorStatePtr (AOT iterator_to_array / dominate-uses, #26802).
        $objVar = self::normalizeGeneratorObjectVariable($context, $genVar);
        JitGeneratorHelper::ensureTypes($context);
        // Mirror FiberHelperLlvm::loadStateFromFiberObject — propertyFetch returns a
        // KIND_VARIABLE slot (i64*); inttoptr needs the loaded i64 bits (#26819).
        $objVal = $context->helper->loadValue($objVar);
        $stateVar = $context->type->object->propertyFetch(
            $objVal,
            'Generator',
            GeneratorJitHelper::STATE_PROPERTY
        );
        $stateBits = $context->helper->loadValue($stateVar);
        $statePtr = $context->builder->inttoptr(
            $stateBits,
            $context->getTypeFromString('__generator_state__*')
        );
        $genVar->generatorStatePtr = $statePtr;

        return $statePtr;
    }

    public static function resolveResumeLc(Context $context, Variable $genVar): string
    {
        if (null !== $genVar->generatorResumeName) {
            return strtolower($genVar->generatorResumeName);
        }
        $resumeName = self::inferResumeNameFromContext($context, $genVar);
        if (null === $resumeName) {
            throw new \LogicException('Generator missing __generator_resume metadata in JIT');
        }
        $genVar->generatorResumeName = $resumeName;

        return strtolower($resumeName);
    }

    public static function resolveResumeFunction(Context $context, Variable $genVar): Value\Function_
    {
        $resumeLc = self::resolveResumeLc($context, $genVar);
        $resumeFn = $context->functions[$resumeLc] ?? null;
        if (!$resumeFn instanceof Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }

        return $resumeFn;
    }

    /**
     * Populate JIT Generator metadata from a Generator object when call-site tags are missing (#19131).
     */
    public static function hydrateGeneratorMetadata(Context $context, Variable $genVar): bool
    {
        if (
            null !== $genVar->generatorStatePtr
            && null !== $genVar->generatorResumeName
        ) {
            $genVar->isJitGenerator = true;

            return true;
        }
        // Arrays / non-objects are never Generators (#26802).
        if (
            Variable::TYPE_OBJECT !== $genVar->type
            && Variable::TYPE_VALUE !== $genVar->type
        ) {
            return false;
        }
        try {
            self::normalizeGeneratorObjectVariable($context, $genVar);
            self::loadStateFromGeneratorObject($context, $genVar);
            self::resolveResumeLc($context, $genVar);
        } catch (\LogicException) {
            // Drop a half-hydrated inttoptr from a non-Generator object (#26802).
            $genVar->generatorStatePtr = null;
            $genVar->generatorResumeName = null;

            return false;
        }
        $genVar->isJitGenerator = true;

        return true;
    }

    private static function normalizeGeneratorObjectVariable(Context $context, Variable $genVar): Variable
    {
        if (Variable::TYPE_OBJECT === $genVar->type) {
            return $genVar;
        }
        if (Variable::TYPE_VALUE === $genVar->type) {
            $objPtr = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::pointer($context, $genVar->value)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objPtr);
        }

        throw new \LogicException('Generator missing __generator_state in JIT');
    }

    private static function inferResumeNameFromContext(Context $context, Variable $genVar): ?string
    {
        $creators = array_values(array_unique($context->generatorCreators));
        if (1 === \count($creators)) {
            return $creators[0];
        }

        return null;
    }

    public static function boxCurrentOrNull(Context $context, Value $statePtr): Value
    {
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $currentSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer(
            $context,
            $currentSlot,
            $context->builder->structGep($statePtr, $map['current_value'])
        );
        $has = $context->builder->icmp(Builder::INT_NE, $hasCurrent, $i1->constInt(0, false));

        return $context->builder->select(
            $has,
            JitValueBox::pointer($context, $currentSlot),
            JitValueBox::pointer($context, $nullSlot)
        );
    }

    public static function runSingleResume(Context $context, string $resumeLc, Value $statePtr): Value
    {
        $resumeFn = $context->functions[strtolower($resumeLc)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }
        // Zend zend_generator_resume clears ZEND_GENERATOR_AT_FIRST_YIELD (#23713).
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store(
            $i1->constInt(0, false),
            $context->builder->structGep($statePtr, $map['at_first_yield'])
        );

        return $context->builder->call($resumeFn, $statePtr);
    }

    public static function resumeAndBoxYield(Context $context, Variable $genVar): Value
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $genVar);
        self::runSingleResume($context, self::resolveResumeLc($context, $genVar), $statePtr);

        return self::boxCurrentOrNull($context, $statePtr);
    }

    /**
     * Generator::send() resume — first send on unstarted generator opens then resumes past
     * the first yield (bare / value / plain yield) (#18108, #23712, Zend/zend_generators.c).
     */
    public static function resumeSendAndBoxYield(Context $context, Variable $genVar): Value
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $hasReturned = $context->builder->load($context->builder->structGep($statePtr, $map['has_returned']));
        $wasUnstarted = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $resumeIp, $zero),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $hasCurrent, $i1->constInt(0, false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
                    $context->builder->icmp(Builder::INT_EQ, $hasReturned, $i1->constInt(0, false))
                )
            )
        );

        $resumeLc = self::resolveResumeLc($context, $genVar);
        self::runSingleResume($context, $resumeLc, $statePtr);

        $hasCurrentAfter = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $doneAfter = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        // Zend always injects+advances on first send when the open left a live yield.
        $needsAutoContinue = $context->builder->and(
            $wasUnstarted,
            $context->builder->and(
                $context->builder->icmp(Builder::INT_NE, $hasCurrentAfter, $i1->constInt(0, false)),
                $context->builder->icmp(Builder::INT_EQ, $doneAfter, $i1->constInt(0, false))
            )
        );

        $fn = $context->builder->getInsertBlock()->getParent();
        $continueBb = $fn->appendBasicBlock('gen_send_auto_continue');
        $skipBb = $fn->appendBasicBlock('gen_send_auto_skip');
        $context->builder->branchIf($needsAutoContinue, $continueBb, $skipBb);
        $context->builder->positionAtEnd($continueBb);
        self::runSingleResume($context, $resumeLc, $statePtr);
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);

        return self::boxCurrentOrNull($context, $statePtr);
    }

    public static function ensureStarted(Context $context, Variable $genVar): void
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $genVar);
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $resumeIp = $context->builder->load($context->builder->structGep($statePtr, $map['resume_ip']));
        $hasCurrent = $context->builder->load($context->builder->structGep($statePtr, $map['has_current']));
        $done = $context->builder->load($context->builder->structGep($statePtr, $map['done']));
        $hasReturned = $context->builder->load($context->builder->structGep($statePtr, $map['has_returned']));
        $needsStart = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $resumeIp, $zero),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $hasCurrent, $i1->constInt(0, false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, $done, $i1->constInt(0, false)),
                    $context->builder->icmp(Builder::INT_EQ, $hasReturned, $i1->constInt(0, false))
                )
            )
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $startBb = $fn->appendBasicBlock('gen_ensure_start');
        $skipBb = $fn->appendBasicBlock('gen_ensure_skip');
        $context->builder->branchIf($needsStart, $startBb, $skipBb);
        $context->builder->positionAtEnd($startBb);
        self::runSingleResume($context, self::resolveResumeLc($context, $genVar), $statePtr);
        // Zend zend_generator_ensure_initialized sets AT_FIRST_YIELD after open (#23713).
        $context->builder->store(
            $i1->constInt(1, false),
            $context->builder->structGep($statePtr, $map['at_first_yield'])
        );
        $context->builder->branch($skipBb);
        $context->builder->positionAtEnd($skipBb);
    }

    public static function assignValueField(
        Context $context,
        Value $destField,
        Variable $src,
        ?Operand $srcOp = null
    ): void {
        \PHPCompiler\JIT\FiberHelper::assignValueField($context, $destField, $src, $srcOp);
    }

    private static function emitForeachGeneratorByRefError(Context $context, ?\PHPCompiler\JIT $jit): void
    {
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'Exception',
                GeneratorJitHelper::FOREACH_GENERATOR_BYREF_ERROR,
                $jit
            );

            return;
        }
        TryCatchHelper::emitCatchableClassError($context, 'Exception', GeneratorJitHelper::FOREACH_GENERATOR_BYREF_ERROR);
    }
}
