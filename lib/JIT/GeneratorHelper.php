<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\OpCode;
use PHPCompiler\VM\GeneratorIteratorJitHelper;
use PHPCompiler\VM\GeneratorJitHelper;
use PHPCompiler\VM\GeneratorYieldFromJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmGenerator;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPLLVM\Value;

/**
 * MCJIT lowering for user generators (issue #167, #3074).
 *
 * Switch-on-resume-ip for generator bodies; foreach over Generator uses this helper.
 * PHP SSOT: {@see GeneratorJitHelper} CFG · {@see VmGenerator} state · {@see GeneratorYieldFromJitHelper} (#10105).
 * php-src: Zend/zend_generators.c.
 */
final class GeneratorHelper
{
    public const TARGET_PROPERTY = VmGenerator::TARGET_PROPERTY;

    /** int64 property holding {@see __generator_state__*} bits (#3115). */
    public const STATE_PROPERTY = VmGenerator::STATE_PROPERTY;

    /** zend_generators.c — foreach by-ref requires generator yields-by-ref (#4599). */
    public const FOREACH_GENERATOR_BYREF_ERROR = GeneratorJitHelper::FOREACH_GENERATOR_BYREF_ERROR;

    public static function registerJitMethods(Context $context): void
    {
        $context->functionProxies['generator::send'] = new Call\GeneratorSend();
        $context->functionProxies['generator::throw'] = new Call\GeneratorThrow();
        $context->functionProxies['generator::getreturn'] = new Call\GeneratorGetReturn();
        $context->functionProxies['generator::next'] = new Call\GeneratorNext();
        $context->functionProxies['generator::current'] = new Call\GeneratorCurrent();
        $context->functionProxies['generator::rewind'] = new Call\GeneratorRewind();
        $context->functionProxies['generator::valid'] = new Call\GeneratorValid();
        $context->functionProxies['generator::key'] = new Call\GeneratorKey();
    }

    public static function ensureTypes(Context $context): void
    {
        VmGenerator::ensureJitTypes($context);
    }

    public static function registerCreator(Context $context, string $funcLc, string $resumeInternalName): void
    {
        $context->generatorCreators[strtolower($funcLc)] = $resumeInternalName;
    }

    public static function creatorResumeName(Context $context, string $funcLc): ?string
    {
        return GeneratorJitHelper::creatorResumeName($context, $funcLc);
    }

    public static function isGeneratorVariable(Variable $var): bool
    {
        return GeneratorJitHelper::isGeneratorVariable($var);
    }

    /**
     * @return list<array{kind: string, op: OpCode, block: Block}>
     */
    public static function collectResumePoints(Block $entry): array
    {
        return GeneratorJitHelper::collectResumePoints($entry);
    }

    private static function compileYieldPrefix(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Block $block,
        int $startIndex,
        int $yieldIdx,
        \PHPLLVM\BasicBlock $entryBlock
    ): \PHPLLVM\BasicBlock {
        if ($startIndex >= $yieldIdx) {
            return $entryBlock;
        }
        $context = $jit->context;
        $savedStorage = $context->scope->blockStorage;
        $context->scope->blockStorage = new \SplObjectStorage();
        $exit = $jit->compileGeneratorResumePrefix($func, $block, $startIndex, $yieldIdx, $entryBlock);
        $context->builder->positionAtEnd($exit);
        $context->scope->blockStorage = $savedStorage;

        return $exit;
    }

    /**
     * @param array{kind: string, op: OpCode, block: Block} $firstPoint
     */
    private static function compileEntryLeadIn(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Block $entry,
        array $firstPoint,
        \PHPLLVM\BasicBlock $caseBlock
    ): \PHPLLVM\BasicBlock {
        $context = $jit->context;
        $trySetup = GeneratorJitHelper::findTrySetupForYieldBlock($entry, $firstPoint['block']);
        if (null !== $trySetup) {
            [$handlerBlock, $tryOp, $tryIndex] = $trySetup;
            $tryBodyBb = $func->appendBasicBlock('gen_try_body_entry');
            $context->builder->positionAtEnd($caseBlock);
            TryCatchHelper::beginTryGeneratorResume(
                $jit,
                $func,
                $context,
                $handlerBlock,
                $tryOp,
                $tryIndex,
                [],
                $caseBlock,
                $tryBodyBb
            );
            $context->builder->positionAtEnd($tryBodyBb);
            $caseBlock = $tryBodyBb;
        }
        // for/while cold start: run entry ASSIGN ($i=0) before first yield (#35142).
        if ($firstPoint['block'] === $entry) {
            return $caseBlock;
        }
        $stop = $entry->nOpCodes;
        for ($i = 0; $i < $stop; ++$i) {
            $op = $entry->opCodes[$i];
            if (
                OpCode::TYPE_JUMP === $op->type
                || OpCode::TYPE_JUMPIF === $op->type
                || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED === $op->type
                || OpCode::TYPE_RETURN === $op->type
                || OpCode::TYPE_RETURN_VOID === $op->type
                || OpCode::TYPE_YIELD === $op->type
            ) {
                $stop = $i;
                break;
            }
        }
        if ($stop > 0) {
            return self::compileYieldPrefix($jit, $func, $entry, 0, $stop, $caseBlock);
        }

        return $caseBlock;
    }

    /**
     * @param array{kind: string, op: OpCode, block: Block} $prev
     * @param array{kind: string, op: OpCode, block: Block} $curr
     */
    private static function compileCrossBlockResumePrefix(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        array $prev,
        array $curr,
        \PHPLLVM\BasicBlock $caseBlock
    ): void {
        $prevBlock = $prev['block'];
        $prevAfter = GeneratorJitHelper::opcodeIndex($prevBlock, $prev['op']) + 1;
        if ($prevAfter < $prevBlock->nOpCodes) {
            self::compileYieldPrefix($jit, $func, $prevBlock, $prevAfter, $prevBlock->nOpCodes, $caseBlock);
        }
        $currBlock = $curr['block'];
        $currIdx = GeneratorJitHelper::opcodeIndex($currBlock, $curr['op']);
        if ($currIdx > 0) {
            self::compileYieldPrefix($jit, $func, $currBlock, 0, $currIdx, $caseBlock);
        }
    }

    public static function compileResumeFunction(
        \PHPCompiler\JIT $jit,
        string $internalName,
        Block $block,
        string $logicalName
    ): Value {
        $context = $jit->context;
        self::ensureTypes($context);
        $resumeName = $internalName.'__resume';
        $lc = strtolower($resumeName);
        self::registerCreator($context, $logicalName, $resumeName);
        $decl = $block->func ?? null;
        if (
            null !== $decl
            && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0
        ) {
            $context->functionReturnsRef[strtolower($logicalName)] = true;
        }
        if (isset($context->functions[$lc])) {
            return $context->functions[$lc];
        }

        $statePtrTy = $context->getTypeFromString('__generator_state__*');
        $i64 = $context->getTypeFromString('int64');
        $func = $context->module->addFunction(
            GeneratorJitHelper::llvmInternalName($resumeName),
            $context->context->functionType($i64, false, $statePtrTy)
        );
        $stateParam = $func->getParam(0);
        $savedBuilder = $context->builder;
        $savedIntrinsic = $context->intrinsic;
        $savedLoweringLlvm = $context->loweringLlvmFunction;
        $savedActiveFunction = $context->activeFunction;
        $context->builder = $context->context->builderCreate();
        // Intrinsic caches the builder used at construction. Leaving it on the outer
        // builder makes ReadonlyRaise / AssertionErrorRaise memcpy parentless while
        // phis land in the resume builder → Module.php:180 on send+echo (#35178).
        $context->intrinsic = $context->module->intrinsic($context->builder);
        $context->compilingGeneratorResume = true;
        $context->generatorStateParam = $stateParam;
        // Pin so JitValueBox::copyFromPointer / BasicBlockHelper::append stay in this
        // resume fn — otherwise value_copy_* BBs land in the outer void/user fn and
        // module verify fails (cross-function br / ret i64 in void) (#33706 / re-#26819).
        $context->loweringLlvmFunction = $func;
        $context->activeFunction = $lc;

        $entry = $func->appendBasicBlock('gen_entry');
        $context->builder->positionAtEnd($entry);
        $points = self::collectResumePoints($block);
        $n = count($points);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');

        $context->generatorFrameLocalIndex = [];
        $context->generatorYieldPointIndex = [];
        $context->generatorResumeContinuations = [];
        self::indexNamedLocals($block, $context);
        $paramNames = self::collectParamNamesInOrder($block);
        $context->generatorCreatorParamNames[strtolower($logicalName)] = $paramNames;
        $context->generatorCreatorFrameIndex[strtolower($resumeName)] = $context->generatorFrameLocalIndex;
        foreach ($points as $i => $point) {
            $context->generatorYieldPointIndex[spl_object_id($point['op'])] = $i;
        }

        self::emitEnsureFrameSlots($context, $stateParam, \count($context->generatorFrameLocalIndex));
        self::materializeFrameLocalPtrs($context, $stateParam);
        // Pre-bind frame CVs into namedVariableBindings. ARG_RECV is a no-op under
        // resume, so getVariableFromOp would otherwise allocate a fresh null box for
        // named Temporaries (yield $n → NULL) (#35142).
        foreach (array_keys($context->generatorFrameLocalIndex) as $frameName) {
            Variable::fromGeneratorFrameLocal($context, $frameName);
        }

        // auto_key is initialized at create/reset — do not zero on every resume (#22343).
        $resumeIp = $context->builder->load($context->builder->structGep($stateParam, $map['resume_ip']));
        $doneBb = $func->appendBasicBlock('gen_done');
        // Cases 0..n: 0 = cold start, i+1 = continuation after yield i (#35142).
        $switchInst = $context->builder->branchSwitch($resumeIp, $doneBb, $n + 1);

        $caseBlocks = [];
        for ($i = 0; $i <= $n; ++$i) {
            $caseBb = $func->appendBasicBlock('gen_case_'.$i);
            $switchInst->addCase($sizeT->constInt($i, false), $caseBb);
            $caseBlocks[$i] = $caseBb;
            $context->generatorResumeContinuations[$i] = $caseBb;
        }

        $context->generatorCatchDispatchEntry = [];
        $handlerStackDepth = \count($context->tryCatch->handlerStack);
        $trySetup = [] !== $points ? GeneratorJitHelper::findTrySetupForYieldBlock($block, $points[0]['block']) : null;
        // Full-CFG resume path still trips LLVM dominance on looped JUMPIF (#35142);
        // use segment lowering + heap frame locals + entry/post-yield fixes below.
        $useFullCfg = false;
        if (null !== $trySetup) {
            [$handlerBlock, , $tryIndex] = $trySetup;
            $catchArms = TryCatchHelper::collectCatchOps($handlerBlock, $tryIndex);
            foreach ($points as $i => $point) {
                if (0 === $i) {
                    continue;
                }
                foreach ($catchArms as $arm) {
                    $catchBody = $arm['op']->block1;
                    if ($catchBody instanceof Block && GeneratorJitHelper::cfgBlockContains($catchBody, $point['block'])) {
                        $context->generatorCatchDispatchEntry[spl_object_id($catchBody)] =
                            $func->appendBasicBlock('gen_catch_resume_'.$i);
                        break;
                    }
                }
            }
        }

        if ($useFullCfg) {
            // Single CFG compile: yields suspend and continue in gen_case_{i+1} (#35142).
            // Pre-wire pending throw/send inject so switch lands on caseBlocks[i] then
            // falls into the continuation BB that receives post-yield opcodes.
            for ($i = 0; $i <= $n; ++$i) {
                $cont = GeneratorIteratorJitHelper::emitInjectPendingThrow(
                    $jit,
                    $func,
                    $stateParam,
                    $caseBlocks[$i]
                );
                if ($i > 0 && $i - 1 < $n && 'yield' === $points[$i - 1]['kind']) {
                    $cont = GeneratorIteratorJitHelper::emitInjectPendingSend(
                        $jit,
                        $func,
                        $points[$i - 1]['block'],
                        $points[$i - 1]['op'],
                        $stateParam,
                        $cont
                    );
                }
                $context->generatorResumeContinuations[$i] = $cont;
            }
            $startBb = $context->generatorResumeContinuations[0];
            $context->builder->positionAtEnd($startBb);
            $savedStorage = $context->scope->blockStorage;
            $context->scope->blockStorage = new \SplObjectStorage();
            $jit->compileGeneratorResumePrefix($func, $block, 0, $block->nOpCodes, $startBb);
            $context->scope->blockStorage = $savedStorage;

            // Continuations that never received a terminator — branch to done.
            for ($i = 1; $i <= $n; ++$i) {
                $cont = $context->generatorResumeContinuations[$i];
                $context->builder->positionAtEnd($cont);
                if (null === $cont->getTerminator()) {
                    $context->builder->branch($doneBb);
                }
            }
        } else {
            // Legacy try/catch resume segments (#27518 / #35008).
            for ($i = 0; $i < $n; ++$i) {
                $context->builder->positionAtEnd($caseBlocks[$i]);
                $prefixEntry = $caseBlocks[$i];
                $point = $points[$i];
                $pointBlock = $point['block'];
                $yieldIdx = GeneratorJitHelper::opcodeIndex($pointBlock, $point['op']);
                $catchDispatchBb = $context->generatorCatchDispatchEntry[spl_object_id($pointBlock)] ?? null;
                if (0 === $i && $pointBlock !== $block) {
                    $prefixEntry = self::compileEntryLeadIn($jit, $func, $block, $point, $prefixEntry);
                }
                $prefixEntry = GeneratorIteratorJitHelper::emitInjectPendingThrow(
                    $jit,
                    $func,
                    $stateParam,
                    $prefixEntry
                );
                if ($i > 0 && 'yield' === $points[$i - 1]['kind']) {
                    $prefixEntry = GeneratorIteratorJitHelper::emitInjectPendingSend(
                        $jit,
                        $func,
                        $points[$i - 1]['block'],
                        $points[$i - 1]['op'],
                        $stateParam,
                        $prefixEntry
                    );
                }
                if (null !== $catchDispatchBb) {
                    $compiledTryThrowSuffix = false;
                    if ($i > 0 && $points[$i - 1]['block'] !== $pointBlock) {
                        $prev = $points[$i - 1];
                        $prevBlock = $prev['block'];
                        $prevAfter = GeneratorJitHelper::opcodeIndex($prevBlock, $prev['op']) + 1;
                        $throwEnd = null;
                        for ($oi = $prevAfter; $oi < $prevBlock->nOpCodes; ++$oi) {
                            if (OpCode::TYPE_THROW === $prevBlock->opCodes[$oi]->type) {
                                $throwEnd = $oi + 1;
                                break;
                            }
                        }
                        if (null !== $throwEnd) {
                            self::compileYieldPrefix(
                                $jit,
                                $func,
                                $prevBlock,
                                $prevAfter,
                                $throwEnd,
                                $prefixEntry
                            );
                            $compiledTryThrowSuffix = true;
                        }
                    }
                    if (!$compiledTryThrowSuffix) {
                        $context->builder->positionAtEnd($prefixEntry);
                        if (null === $prefixEntry->getTerminator()) {
                            $context->builder->branch($catchDispatchBb);
                        }
                    }
                } elseif ($i > 0 && $points[$i - 1]['block'] !== $pointBlock) {
                    self::compileCrossBlockResumePrefix($jit, $func, $points[$i - 1], $point, $prefixEntry);
                }
                $prefixStart = GeneratorJitHelper::resumePrefixStart($points, $i);
                if ('yield' === $point['kind']) {
                    $yieldBb = $catchDispatchBb ?? $prefixEntry;
                    if (null === $catchDispatchBb && $prefixStart < $yieldIdx) {
                        $yieldBb = self::compileYieldPrefix(
                            $jit,
                            $func,
                            $pointBlock,
                            $prefixStart,
                            $yieldIdx,
                            $prefixEntry
                        );
                    } elseif (null !== $catchDispatchBb) {
                        $context->builder->positionAtEnd($catchDispatchBb);
                        if ($prefixStart < $yieldIdx) {
                            $yieldBb = self::compileYieldPrefix(
                                $jit,
                                $func,
                                $pointBlock,
                                $prefixStart,
                                $yieldIdx,
                                $catchDispatchBb
                            );
                        } else {
                            $yieldBb = $catchDispatchBb;
                        }
                    }
                    $context->builder->positionAtEnd($yieldBb);
                    GeneratorIteratorJitHelper::emitYieldPoint($jit, $pointBlock, $point['op'], $stateParam, $i + 1);
                } else {
                    GeneratorYieldFromJitHelper::emitYieldFromPoint(
                        $jit,
                        $pointBlock,
                        $point['op'],
                        $stateParam,
                        $i
                    );
                }
            }
            if ($n >= 0 && isset($caseBlocks[$n]) && null === $caseBlocks[$n]->getTerminator()) {
                $context->builder->positionAtEnd($caseBlocks[$n]);
                $context->builder->branch($doneBb);
            }
        }

        // Resume past the last yield continuation (default / empty cont): capture return (#28624).
        // A TYPE_THROW tail must be lowered — Zend zend_generator_resume (#34455).
        $context->builder->positionAtEnd($doneBb);
        $doneEntry = GeneratorIteratorJitHelper::emitInjectPendingThrow(
            $jit,
            $func,
            $stateParam,
            $doneBb
        );
        if (!$useFullCfg && $n > 0 && 'yield' === $points[$n - 1]['kind']) {
            $doneEntry = GeneratorIteratorJitHelper::emitInjectPendingSend(
                $jit,
                $func,
                $points[$n - 1]['block'],
                $points[$n - 1]['op'],
                $stateParam,
                $doneEntry
            );
        }
        $context->builder->positionAtEnd($doneEntry);
        $tailTerminated = false;
        if (!$useFullCfg) {
            $tailTerminated = self::compilePostYieldReturnTail(
                $jit,
                $func,
                $points,
                $stateParam,
                $doneEntry,
                $map
            );
        }
        if (!$tailTerminated) {
            $insert = $context->builder->getInsertBlock();
            if (null === $insert || null === $insert->getTerminator()) {
                $context->builder->store(
                    $i1->constInt(1, false),
                    $context->builder->structGep($stateParam, $map['has_returned'])
                );
                $context->builder->store(
                    $i1->constInt(1, false),
                    $context->builder->structGep($stateParam, $map['done'])
                );
                $context->builder->store(
                    $i1->constInt(0, false),
                    $context->builder->structGep($stateParam, $map['has_current'])
                );
                $context->builder->returnValue($i64->constInt(0, false));
            }
        }
        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->intrinsic = $savedIntrinsic;
        $context->compilingGeneratorResume = false;
        $context->generatorStateParam = null;
        $context->generatorFrameLocalIndex = [];
        $context->generatorFrameLocalPtrs = [];
        $context->generatorYieldPointIndex = [];
        $context->generatorResumeContinuations = [];
        $context->loweringLlvmFunction = $savedLoweringLlvm;
        $context->activeFunction = $savedActiveFunction;
        $context->generatorCatchDispatchEntry = [];
        // beginTryGeneratorResume pushes handlers that finishPostTryOpcode never pops (#27518).
        while (\count($context->tryCatch->handlerStack) > $handlerStackDepth) {
            TryCatchHelper::popHandler($context);
        }

        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = 'int64';
        $context->functionProxies[$lc] = new Native($func, $resumeName, [$statePtrTy], []);

        return $func;
    }

    /** @return list<string> */
    private static function collectParamNamesInOrder(Block $entry): array
    {
        $names = [];
        foreach ($entry->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || null === $op->arg1) {
                continue;
            }
            $operand = $entry->getOperand((int) $op->arg1);
            if (!$operand instanceof Operand) {
                continue;
            }
            $name = OperandName::resolve($operand);
            if (null !== $name && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param Block $entry */
    private static function indexNamedLocals(Block $entry, Context $context): void
    {
        $visited = new \SplObjectStorage();
        $stack = [$entry];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof Block || $visited->contains($block)) {
                continue;
            }
            $visited->attach($block);
            foreach ($block->opCodes as $opCode) {
                foreach ([$opCode->arg1, $opCode->arg2, $opCode->arg3] as $slot) {
                    if (null === $slot) {
                        continue;
                    }
                    $operand = $block->getOperand((int) $slot);
                    if (!$operand instanceof Operand) {
                        continue;
                    }
                    $name = OperandName::resolve($operand);
                    if (null === $name || '' === $name || 'this' === $name) {
                        continue;
                    }
                    if (!isset($context->generatorFrameLocalIndex[$name])) {
                        $context->generatorFrameLocalIndex[$name] = \count($context->generatorFrameLocalIndex);
                    }
                }
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    private static function emitEnsureFrameSlots(Context $context, Value $stateParam, int $count): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $frameGep = $context->builder->structGep($stateParam, $map['frame_slots']);
        $existing = $context->builder->load($frameGep);
        $valueTy = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $fn = $context->builder->getInsertBlock()->getParent();
        $needAlloc = $fn->appendBasicBlock('gen_frame_alloc');
        $haveFrame = $fn->appendBasicBlock('gen_frame_ready');
        $isNull = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $existing,
            $valuePtrTy->constNull()
        );
        $context->builder->branchIf($isNull, $needAlloc, $haveFrame);
        $context->builder->positionAtEnd($needAlloc);
        if ($count <= 0) {
            $context->builder->store($valuePtrTy->constNull(), $frameGep);
            $context->builder->branch($haveFrame);
            $context->builder->positionAtEnd($haveFrame);

            return;
        }
        // sizeof(__value__) * count via GEP null trick.
        $oneSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $context->context->int32Type()->constInt(1, false)
            ),
            $context->getTypeFromString('size_t')
        );
        $bytes = $context->builder->mul(
            $oneSize,
            $context->getTypeFromString('size_t')->constInt($count, false)
        );
        $raw = $context->builder->call($context->lookupFunction('__mm__malloc'), $bytes);
        $slots = $context->builder->pointerCast($raw, $valuePtrTy);
        for ($i = 0; $i < $count; ++$i) {
            $slot = $context->builder->gep(
                $slots,
                $context->context->int32Type()->constInt($i, false)
            );
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );
        }
        $context->builder->store($slots, $frameGep);
        $context->builder->branch($haveFrame);
        $context->builder->positionAtEnd($haveFrame);
    }

    /** Materialize dominating GEPs for each named frame slot after ensure (#35142). */
    private static function materializeFrameLocalPtrs(Context $context, Value $stateParam): void
    {
        $context->generatorFrameLocalPtrs = [];
        if ([] === $context->generatorFrameLocalIndex) {
            return;
        }
        $map = $context->structFieldMap['__generator_state__'];
        $slotsPtr = $context->builder->load(
            $context->builder->structGep($stateParam, $map['frame_slots'])
        );
        foreach ($context->generatorFrameLocalIndex as $name => $index) {
            $context->generatorFrameLocalPtrs[$name] = $context->builder->gep(
                $slotsPtr,
                $context->context->int32Type()->constInt($index, false)
            );
        }
    }

    /**
     * Locate SMALLER/… + JUMPIF in a for/while header (optional leading CONST_FETCH).
     *
     * @return array{0: OpCode, 1: OpCode}|null
     */
    private static function findLoopHeaderCompare(Block $header): ?array
    {
        $n = $header->nOpCodes;
        for ($i = 0; $i < $n - 1; ++$i) {
            $cmpOp = $header->opCodes[$i];
            $jumpIf = $header->opCodes[$i + 1];
            if (OpCode::TYPE_JUMPIF !== $jumpIf->type) {
                continue;
            }
            if (
                OpCode::TYPE_SMALLER !== $cmpOp->type
                && OpCode::TYPE_SMALLER_OR_EQUAL !== $cmpOp->type
                && OpCode::TYPE_GREATER !== $cmpOp->type
                && OpCode::TYPE_GREATER_OR_EQUAL !== $cmpOp->type
            ) {
                continue;
            }

            return [$cmpOp, $jumpIf];
        }

        return null;
    }

    /**
     * Loop bound as i64: frame local, block constant, literal, or folded CONST_FETCH (#35166).
     */
    private static function resolveHandLowerLoopBoundI64(
        Context $context,
        Block $cmpBlock,
        OpCode $cmpOp,
        string $iName
    ): ?Value {
        $leftSlot = $cmpOp->arg2;
        $rightSlot = $cmpOp->arg3;
        if (null === $rightSlot && null === $leftSlot) {
            return null;
        }
        $leftName = null;
        if (null !== $leftSlot) {
            $leftOp = $cmpBlock->getOperand((int) $leftSlot);
            $leftName = null !== $leftOp ? OperandName::resolve($leftOp) : null;
        }
        $rightName = null;
        if (null !== $rightSlot) {
            $rightOp = $cmpBlock->getOperand((int) $rightSlot);
            $rightName = null !== $rightOp ? OperandName::resolve($rightOp) : null;
        }
        // Common: $i < bound — bound on RHS.
        if ($leftName === $iName || (null === $leftName && $rightName !== $iName)) {
            if (null === $rightSlot) {
                return null;
            }

            return self::operandSlotToI64Bound($context, $cmpBlock, (int) $rightSlot, $iName);
        }
        // bound ? $i — bound on LHS.
        if ($rightName === $iName && null !== $leftSlot) {
            return self::operandSlotToI64Bound($context, $cmpBlock, (int) $leftSlot, $iName);
        }

        return null;
    }

    private static function operandSlotToI64Bound(
        Context $context,
        Block $block,
        int $slot,
        string $iName
    ): ?Value {
        $i64 = $context->getTypeFromString('int64');
        if (isset($block->constants[$slot])) {
            $c = $block->constants[$slot];
            if (VmVariable::TYPE_INTEGER === $c->type) {
                return $i64->constInt($c->toInt(), true);
            }
        }
        $op = $block->getOperand($slot);
        if ($op instanceof Literal && \is_int($op->value)) {
            return $i64->constInt($op->value, true);
        }
        $name = null !== $op ? OperandName::resolve($op) : null;
        if (null !== $name && $name !== $iName && isset($context->generatorFrameLocalPtrs[$name])) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                JitValueBox::pointer($context, $context->generatorFrameLocalPtrs[$name])
            );
        }
        // Header often does CONST_FETCH N → temp then SMALLER($i, temp) (#35166).
        for ($i = 0; $i < $block->nOpCodes; ++$i) {
            $fetch = $block->opCodes[$i];
            if (OpCode::TYPE_CONST_FETCH !== $fetch->type || (int) $fetch->arg1 !== $slot) {
                continue;
            }
            if (null === $fetch->arg2 || !isset($block->constants[(int) $fetch->arg2])) {
                continue;
            }
            $constName = $block->constants[(int) $fetch->arg2]->toString();
            $folded = self::foldDeclaredGlobalConstInt($context, $constName);
            if (null !== $folded) {
                return $i64->constInt($folded, true);
            }
        }

        return null;
    }

    /** File `const N = 3` may not be in context yet (FUNCDEF runs before DECLARE) (#35166). */
    private static function foldDeclaredGlobalConstInt(Context $context, string $constName): ?int
    {
        if (isset($context->constants[$constName][2]) && \is_int($context->constants[$constName][2])) {
            return $context->constants[$constName][2];
        }
        $nameOp = new Literal($constName);
        $fetched = $context->constantFetch($nameOp);
        if (isset($context->constants[$constName][2]) && \is_int($context->constants[$constName][2])) {
            return $context->constants[$constName][2];
        }
        unset($fetched);
        // FUNCDEF compiles before DECLARE_GLOBAL_CONST on the same script block — scan
        // already-entered blocks (enclosing {main} is in blockStorage) for the declare.
        if (!$context->scope->blockStorage instanceof \SplObjectStorage) {
            return null;
        }
        foreach ($context->scope->blockStorage as $scanBlock) {
            if (!$scanBlock instanceof Block) {
                continue;
            }
            foreach ($scanBlock->opCodes as $decl) {
                if (OpCode::TYPE_DECLARE_GLOBAL_CONST !== $decl->type) {
                    continue;
                }
                if (null === $decl->arg1 || null === $decl->arg2) {
                    continue;
                }
                if (!isset($scanBlock->constants[(int) $decl->arg1])
                    || !isset($scanBlock->constants[(int) $decl->arg2])
                ) {
                    continue;
                }
                if ($scanBlock->constants[(int) $decl->arg1]->toString() !== $constName) {
                    continue;
                }
                $val = $scanBlock->constants[(int) $decl->arg2];
                if (VmVariable::TYPE_INTEGER === $val->type) {
                    return $val->toInt();
                }
            }
        }

        return null;
    }

    /**
     * while ($i < $n) { yield; $i++; } — INC lives in the yield block (#35142).
     * Bound may be a param frame local or a literal/const (#35166).
     *
     * @param array{kind: string, op: OpCode, block: Block} $last
     * @param array<string, int>                           $map
     */
    private static function tryHandLowerWhileLoopAfterYield(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        array $last,
        Block $tailBlock,
        int $tailStart,
        Value $stateParam,
        \PHPLLVM\BasicBlock $doneEntry,
        array $map,
        int $resumePointCount
    ): bool {
        $context = $jit->context;
        $incWriteName = null;
        $header = null;
        for ($i = $tailStart; $i < $tailBlock->nOpCodes; ++$i) {
            $op = $tailBlock->opCodes[$i];
            if (
                OpCode::TYPE_POST_INC === $op->type
                || OpCode::TYPE_PRE_INC === $op->type
                || OpCode::TYPE_POST_DEC === $op->type
                || OpCode::TYPE_PRE_DEC === $op->type
            ) {
                if (null === $incWriteName) {
                    $writeSlot = $op->arg3 ?? $op->arg2;
                    if (null !== $writeSlot) {
                        $incWriteName = OperandName::resolve($tailBlock->getOperand((int) $writeSlot));
                    }
                }
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type && $i + 1 === $tailBlock->nOpCodes) {
                $header = $op->block1 ?? $op->block2;
                break;
            }

            return false;
        }
        if (!$header instanceof Block || null === $incWriteName || '' === $incWriteName) {
            return false;
        }
        $cmpPair = self::findLoopHeaderCompare($header);
        if (null === $cmpPair) {
            return false;
        }
        [$cmpOp] = $cmpPair;

        $iName = $incWriteName;
        if (!isset($context->generatorFrameLocalPtrs[$iName])) {
            return false;
        }
        $nVal = self::resolveHandLowerLoopBoundI64($context, $header, $cmpOp, $iName);
        if (null === $nVal) {
            return false;
        }

        $context->builder->positionAtEnd($doneEntry);
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $yieldBb = $func->appendBasicBlock('gen_while_yield');
        $exitBb = $func->appendBasicBlock('gen_while_exit');
        $iPtr = JitValueBox::pointer($context, $context->generatorFrameLocalPtrs[$iName]);
        $iVal = $context->builder->call($context->lookupFunction('__value__readLong'), $iPtr);
        $iNext = $context->builder->add($iVal, $i64->constInt(1, false));
        $context->builder->call($context->lookupFunction('__value__writeLong'), $iPtr, $iNext);
        $pred = match ($cmpOp->type) {
            OpCode::TYPE_SMALLER => \PHPLLVM\Builder::INT_SLT,
            OpCode::TYPE_SMALLER_OR_EQUAL => \PHPLLVM\Builder::INT_SLE,
            OpCode::TYPE_GREATER => \PHPLLVM\Builder::INT_SGT,
            default => \PHPLLVM\Builder::INT_SGE,
        };
        // After inc: while ($i < $n) uses updated $i (same as for's $i++ then re-test).
        $lt = $context->builder->icmp($pred, $iNext, $nVal);
        $context->builder->branchIf($lt, $yieldBb, $exitBb);
        $context->builder->positionAtEnd($exitBb);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_returned']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($stateParam, $map['return_value']))
        );
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($yieldBb);
        GeneratorIteratorJitHelper::emitYieldPoint(
            $jit,
            $last['block'],
            $last['op'],
            $stateParam,
            $resumePointCount
        );

        return true;
    }

    /**
     * Lower opcodes after the last yield up to RETURN or THROW into gen_done.
     *
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     * @param array<string, int>                                  $map
     *
     * @return bool true when the tail ended in TYPE_THROW (caller must not store has_returned)
     */
    private static function compilePostYieldReturnTail(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        array $points,
        Value $stateParam,
        \PHPLLVM\BasicBlock $doneEntry,
        array $map
    ): bool {
        $context = $jit->context;
        $n = \count($points);
        if (0 === $n) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $context->builder->structGep($stateParam, $map['return_value']))
            );

            return false;
        }

        $last = $points[$n - 1];
        $tailBlock = $last['block'];
        $tailStart = GeneratorJitHelper::opcodeIndex($tailBlock, $last['op']) + 1;

        // while: yield; $i++; JUMP→header — INC is in the yield block, not a post-inc
        // block. Compiling POST_INC via resumePrefix then falling through still left
        // JUMPIF on the cold CFG path and failed module verify (string memcpy) (#35142).
        if (self::tryHandLowerWhileLoopAfterYield(
            $jit,
            $func,
            $last,
            $tailBlock,
            $tailStart,
            $stateParam,
            $doneEntry,
            $map,
            $n
        )) {
            return true;
        }

        $returnIdx = null;
        $retOp = null;
        $throwIdx = null;
        for ($i = $tailStart, $end = $tailBlock->nOpCodes; $i < $end; ++$i) {
            $op = $tailBlock->opCodes[$i];
            if (OpCode::TYPE_RETURN === $op->type || OpCode::TYPE_RETURN_VOID === $op->type) {
                $returnIdx = $i;
                $retOp = $op;
                break;
            }
            if (OpCode::TYPE_THROW === $op->type) {
                // yield …; throw — must resume into the throw (#34455).
                $throwIdx = $i;
                break;
            }
            if (OpCode::TYPE_YIELD === $op->type || OpCode::TYPE_YIELD_FROM === $op->type) {
                break;
            }
            // Common CFG: last yield then JUMP into a return/throw block (no further yields).
            // JUMP target is block1 (#35142 — was wrongly block2).
            if (OpCode::TYPE_JUMP === $op->type && null !== $op->block1 && $i + 1 === $end) {
                if ($tailStart < $i) {
                    $savedStorage = $context->scope->blockStorage;
                    $context->scope->blockStorage = new \SplObjectStorage();
                    $exit = $jit->compileGeneratorResumePrefix($func, $tailBlock, $tailStart, $i, $doneEntry);
                    $context->builder->positionAtEnd($exit);
                    $context->scope->blockStorage = $savedStorage;
                    $doneEntry = $exit;
                }
                $tailBlock = $op->block1;
                $tailStart = 0;
                $returnIdx = null;
                $retOp = null;
                $throwIdx = null;
                $reYieldOp = null;
                for ($j = 0, $jEnd = $tailBlock->nOpCodes; $j < $jEnd; ++$j) {
                    $rop = $tailBlock->opCodes[$j];
                    if (OpCode::TYPE_RETURN === $rop->type || OpCode::TYPE_RETURN_VOID === $rop->type) {
                        $returnIdx = $j;
                        $retOp = $rop;
                        break;
                    }
                    if (OpCode::TYPE_THROW === $rop->type) {
                        $throwIdx = $j;
                        break;
                    }
                    if (OpCode::TYPE_YIELD === $rop->type || OpCode::TYPE_YIELD_FROM === $rop->type) {
                        $reYieldOp = $rop;
                        break;
                    }
                }
                // for-loop: after yield, $i++ in frame then re-yield while $i < bound (#35142/#35166).
                if (
                    null === $returnIdx
                    && null === $throwIdx
                    && null === $reYieldOp
                    && $jEnd > 0
                    && OpCode::TYPE_JUMP === $tailBlock->opCodes[$jEnd - 1]->type
                ) {
                    $context->builder->positionAtEnd($doneEntry);
                    // Loop CV is the POST_INC write — not the yield expression (fib yields $a) (#35142).
                    $iName = null;
                    foreach ($tailBlock->opCodes as $incCand) {
                        if (
                            OpCode::TYPE_POST_INC !== $incCand->type
                            && OpCode::TYPE_PRE_INC !== $incCand->type
                            && OpCode::TYPE_POST_DEC !== $incCand->type
                            && OpCode::TYPE_PRE_DEC !== $incCand->type
                        ) {
                            continue;
                        }
                        $writeSlot = $incCand->arg3 ?? $incCand->arg2;
                        if (null === $writeSlot) {
                            continue;
                        }
                        $iName = OperandName::resolve($tailBlock->getOperand((int) $writeSlot));
                        break;
                    }
                    if (null === $iName || '' === $iName) {
                        $yieldValOp = null !== $last['op']->arg2
                            ? $last['block']->getOperand($last['op']->arg2)
                            : null;
                        $iName = null !== $yieldValOp
                            ? OperandName::resolve($yieldValOp)
                            : null;
                    }
                    $header = $tailBlock->opCodes[$jEnd - 1]->block1
                        ?? $tailBlock->opCodes[$jEnd - 1]->block2;
                    $cmpOp = null;
                    $nVal = null;
                    $pred = \PHPLLVM\Builder::INT_SLT;
                    if ($header instanceof Block && null !== $iName && '' !== $iName) {
                        $cmpPair = self::findLoopHeaderCompare($header);
                        if (null !== $cmpPair) {
                            $cmpOp = $cmpPair[0];
                            $nVal = self::resolveHandLowerLoopBoundI64($context, $header, $cmpOp, $iName);
                            $pred = match ($cmpOp->type) {
                                OpCode::TYPE_SMALLER => \PHPLLVM\Builder::INT_SLT,
                                OpCode::TYPE_SMALLER_OR_EQUAL => \PHPLLVM\Builder::INT_SLE,
                                OpCode::TYPE_GREATER => \PHPLLVM\Builder::INT_SGT,
                                default => \PHPLLVM\Builder::INT_SGE,
                            };
                        }
                    }
                    $i1 = $context->getTypeFromString('int1');
                    $i64 = $context->getTypeFromString('int64');
                    $yieldBb = $func->appendBasicBlock('gen_loop_yield');
                    $exitBb = $func->appendBasicBlock('gen_loop_exit');
                    if (
                        null !== $iName
                        && null !== $nVal
                        && isset($context->generatorFrameLocalPtrs[$iName])
                    ) {
                        $iPtr = JitValueBox::pointer($context, $context->generatorFrameLocalPtrs[$iName]);
                        $iVal = $context->builder->call($context->lookupFunction('__value__readLong'), $iPtr);
                        $iNext = $context->builder->add($iVal, $i64->constInt(1, false));
                        $context->builder->call($context->lookupFunction('__value__writeLong'), $iPtr, $iNext);
                        $lt = $context->builder->icmp($pred, $iNext, $nVal);
                        $context->builder->branchIf($lt, $yieldBb, $exitBb);
                    } else {
                        $context->builder->branch($exitBb);
                    }
                    $context->builder->positionAtEnd($exitBb);
                    $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
                    $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_returned']));
                    $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
                    $context->builder->call(
                        $context->lookupFunction('__value__writeNull'),
                        JitValueBox::pointer($context, $context->builder->structGep($stateParam, $map['return_value']))
                    );
                    $context->builder->returnValue($i64->constInt(0, false));
                    $context->builder->positionAtEnd($yieldBb);
                    GeneratorIteratorJitHelper::emitYieldPoint(
                        $jit,
                        $last['block'],
                        $last['op'],
                        $stateParam,
                        $n
                    );

                    return true;
                }
                break;
            }
        }

        if (null !== $throwIdx) {
            // Inclusive of THROW — emitThrow terminates the resume BB (#34455).
            $savedStorage = $context->scope->blockStorage;
            $context->scope->blockStorage = new \SplObjectStorage();
            $jit->compileGeneratorResumePrefix(
                $func,
                $tailBlock,
                $tailStart,
                $throwIdx + 1,
                $doneEntry
            );
            $context->scope->blockStorage = $savedStorage;

            return true;
        }

        if (null !== $returnIdx && $tailStart < $returnIdx) {
            $savedStorage = $context->scope->blockStorage;
            $context->scope->blockStorage = new \SplObjectStorage();
            $exit = $jit->compileGeneratorResumePrefix(
                $func,
                $tailBlock,
                $tailStart,
                $returnIdx,
                $doneEntry
            );
            $context->builder->positionAtEnd($exit);
            $context->scope->blockStorage = $savedStorage;
        }

        if (null !== $retOp && OpCode::TYPE_RETURN === $retOp->type && null !== $retOp->arg1) {
            $retVar = $context->getVariableFromOp($tailBlock->getOperand($retOp->arg1));
            self::assignValueField(
                $context,
                $context->builder->structGep($stateParam, $map['return_value']),
                $retVar,
                $tailBlock->getOperand($retOp->arg1)
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $context->builder->structGep($stateParam, $map['return_value']))
            );
        }

        return false;
    }
    public static function prefixOpcodesSafeForYieldFromInit(Block $block, int $yieldFromIndex): bool
    {
        return GeneratorJitHelper::prefixOpcodesSafeForYieldFromInit($block, $yieldFromIndex);
    }

    /**
     * True when [$start, $end) contains no yield / yield from (safe to compile for container setup).
     */
    public static function prefixSegmentSafeForYieldFromInit(Block $block, int $start, int $end): bool
    {
        return GeneratorJitHelper::prefixSegmentSafeForYieldFromInit($block, $start, $end);
    }

    /**
     * When yield from delegates to a nested generator call (yield from inner()), return inner resume name.
     */
    public static function resolveYieldFromGeneratorResumeName(
        Block $block,
        OpCode $yieldFromOp,
        Context $context
    ): ?string {
        return GeneratorJitHelper::resolveYieldFromGeneratorResumeName($block, $yieldFromOp, $context);
    }

    public static function emitCreateFromCall(
        \PHPCompiler\JIT $jit,
        string $resumeInternalName,
        array $callArgs = []
    ): Variable {
        return VmGenerator::emitCreateFromCall($jit, $resumeInternalName, $callArgs);
    }

    public static function compileIterValid(Context $context, Variable $gen): Value
    {
        return GeneratorIteratorJitHelper::compileIterValid($context, $gen);
    }

    public static function compileIterKey(Context $context, Variable $gen): Variable
    {
        return GeneratorIteratorJitHelper::compileIterKey($context, $gen);
    }

    public static function compileIterValue(Context $context, Variable $gen): Variable
    {
        return GeneratorIteratorJitHelper::compileIterValue($context, $gen);
    }

    public static function compileIterValueByRef(
        Context $context,
        Variable $gen,
        ?\PHPCompiler\JIT $jit = null
    ): Variable {
        return GeneratorIteratorJitHelper::compileIterValueByRef($context, $gen, $jit);
    }

    public static function loadStateFromGeneratorObject(Context $context, Variable $genVar): Value
    {
        return GeneratorIteratorJitHelper::loadStateFromGeneratorObject($context, $genVar);
    }

    public static function resolveResumeLc(Context $context, Variable $genVar): string
    {
        return GeneratorIteratorJitHelper::resolveResumeLc($context, $genVar);
    }

    public static function resolveResumeFunction(Context $context, Variable $genVar): Value\Function_
    {
        return GeneratorIteratorJitHelper::resolveResumeFunction($context, $genVar);
    }

    public static function hydrateGeneratorMetadata(Context $context, Variable $genVar): bool
    {
        return GeneratorIteratorJitHelper::hydrateGeneratorMetadata($context, $genVar);
    }

    public static function boxCurrentOrNull(Context $context, Value $statePtr): Value
    {
        return GeneratorIteratorJitHelper::boxCurrentOrNull($context, $statePtr);
    }

    public static function runSingleResume(Context $context, string $resumeLc, Value $statePtr): Value
    {
        return GeneratorIteratorJitHelper::runSingleResume($context, $resumeLc, $statePtr);
    }

    public static function resumeAndBoxYield(Context $context, Variable $genVar): Value
    {
        return GeneratorIteratorJitHelper::resumeAndBoxYield($context, $genVar);
    }

    public static function resumeSendAndBoxYield(Context $context, Variable $genVar): Value
    {
        return GeneratorIteratorJitHelper::resumeSendAndBoxYield($context, $genVar);
    }

    public static function ensureStarted(Context $context, Variable $genVar): void
    {
        GeneratorIteratorJitHelper::ensureStarted($context, $genVar);
    }

    public static function assignValueField(
        Context $context,
        Value $destField,
        Variable $src,
        ?Operand $srcOp = null
    ): void {
        GeneratorIteratorJitHelper::assignValueField($context, $destField, $src, $srcOp);
    }

    public static function compileIterReset(Context $context, Variable $gen): void
    {
        GeneratorIteratorJitHelper::compileIterReset($context, $gen);
    }

    public static function compileAssertGeneratorIterableForRewind(Context $context, Variable $gen): void
    {
        GeneratorIteratorJitHelper::compileAssertGeneratorIterableForRewind($context, $gen);
    }
}
