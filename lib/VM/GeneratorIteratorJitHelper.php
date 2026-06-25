<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
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
        } else {
            $autoKey = $context->builder->load($context->builder->structGep($stateParam, $map['auto_key']));
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $keyField),
                $context->builder->truncOrBitCast($autoKey, $context->getTypeFromString('int64'))
            );
            $context->builder->store(
                $context->builder->addNoSignedWrap($autoKey, $sizeT->constInt(1, false)),
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
        $fn = $context->builder->getInsertBlock()->getParent();
        $doneBb = $fn->appendBasicBlock('gen_iter_done');
        $resumeBb = $fn->appendBasicBlock('gen_iter_resume');
        $mergeBb = $fn->appendBasicBlock('gen_iter_merge');
        $resumeFn = $context->functions[strtolower($gen->generatorResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }

        $context->builder->branchIf($context->builder->load($doneField), $doneBb, $resumeBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($resumeBb);
        $loopHead = $resumeBb;
        $yielded = $context->builder->call($resumeFn, $state);
        $hasYield = $context->builder->icmp(Builder::INT_NE, $yielded, $i64->constInt(0, false));
        $afterResume = $fn->appendBasicBlock('gen_iter_after_resume');
        $context->builder->branchIf($hasYield, $mergeBb, $afterResume);

        $context->builder->positionAtEnd($afterResume);
        $context->builder->branchIf($context->builder->load($doneField), $doneBb, $loopHead);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $doneBb);
        $phi->addIncoming($i1->constInt(1, false), $resumeBb);

        return $phi;
    }

    public static function compileIterKey(Context $context, Variable $gen): Variable
    {
        if (null === $gen->generatorStatePtr) {
            throw new \LogicException('Generator iterator key requires generator state');
        }
        $keyField = $context->builder->structGep(
            $gen->generatorStatePtr,
            $context->structFieldMap['__generator_state__']['current_key']
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $keyField);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterValue(Context $context, Variable $gen): Variable
    {
        if (null === $gen->generatorStatePtr) {
            throw new \LogicException('Generator iterator value requires generator state');
        }
        $valField = $context->builder->structGep(
            $gen->generatorStatePtr,
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
        self::emitForeachGeneratorByRefError($context, $jit);
        $slot = JitValueBox::alloc($context);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileIterReset(Context $context, Variable $gen): void
    {
        if (null === $gen->generatorStatePtr) {
            return;
        }
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($state, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($state, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['done']));
        VmGenerator::clearYieldFromFields($context, $state);
        VmGenerator::clearPendingAndReturnFields($context, $state);
    }

    public static function loadStateFromGeneratorObject(Context $context, Variable $genVar): Value
    {
        if (null !== $genVar->generatorStatePtr) {
            return $genVar->generatorStatePtr;
        }
        throw new \LogicException('Generator missing __generator_state in JIT');
    }

    public static function resolveResumeLc(Context $context, Variable $genVar): string
    {
        if (null !== $genVar->generatorResumeName) {
            return strtolower($genVar->generatorResumeName);
        }
        throw new \LogicException('Generator missing __generator_resume metadata in JIT');
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

        return $context->builder->call($resumeFn, $statePtr);
    }

    public static function resumeAndBoxYield(Context $context, Variable $genVar): Value
    {
        $statePtr = self::loadStateFromGeneratorObject($context, $genVar);
        self::runSingleResume($context, self::resolveResumeLc($context, $genVar), $statePtr);

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
