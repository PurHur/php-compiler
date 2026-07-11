<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\OpCode;
use PHPCompiler\VM\GeneratorIteratorJitHelper;
use PHPCompiler\VM\GeneratorJitHelper;
use PHPCompiler\VM\GeneratorYieldFromJitHelper;
use PHPCompiler\VM\VmGenerator;
use PHPCfg\Operand;
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
        $trySetup = GeneratorJitHelper::findTrySetupForYieldBlock($entry, $firstPoint['block']);
        if (null === $trySetup) {
            return $caseBlock;
        }
        [$handlerBlock, $tryOp, $tryIndex] = $trySetup;
        $context = $jit->context;
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

        return $tryBodyBb;
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
        $context->builder = $context->context->builderCreate();
        $context->compilingGeneratorResume = true;
        $context->generatorStateParam = $stateParam;

        $entry = $func->appendBasicBlock('gen_entry');
        $context->builder->positionAtEnd($entry);
        $points = self::collectResumePoints($block);
        $n = count($points);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);

        $context->builder->store($zero, $context->builder->structGep($stateParam, $map['auto_key']));
        $resumeIp = $context->builder->load($context->builder->structGep($stateParam, $map['resume_ip']));
        $doneBb = $func->appendBasicBlock('gen_done');
        $switchInst = $context->builder->branchSwitch($resumeIp, $doneBb, $n);

        $caseBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $caseBb = $func->appendBasicBlock('gen_case_'.$i);
            $switchInst->addCase($sizeT->constInt($i, false), $caseBb);
            $caseBlocks[$i] = $caseBb;
        }

        $context->generatorCatchDispatchEntry = [];
        $trySetup = [] !== $points ? GeneratorJitHelper::findTrySetupForYieldBlock($block, $points[0]['block']) : null;
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

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $point = $points[$i];
            $pointBlock = $point['block'];
            $yieldIdx = GeneratorJitHelper::opcodeIndex($pointBlock, $point['op']);
            $prefixEntry = $caseBlocks[$i];
            if (0 === $i && $pointBlock !== $block) {
                $prefixEntry = self::compileEntryLeadIn($jit, $func, $block, $point, $caseBlocks[$i]);
            } elseif ($i > 0 && $points[$i - 1]['block'] !== $pointBlock) {
                self::compileCrossBlockResumePrefix($jit, $func, $points[$i - 1], $point, $caseBlocks[$i]);
            }
            $catchDispatchBb = $context->generatorCatchDispatchEntry[spl_object_id($pointBlock)] ?? null;
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

        $context->builder->positionAtEnd($doneBb);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->compilingGeneratorResume = false;
        $context->generatorStateParam = null;
        $context->generatorCatchDispatchEntry = [];

        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = 'int64';
        $context->functionProxies[$lc] = new Native($func, $resumeName, [$statePtrTy], []);

        return $func;
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
        string $resumeInternalName
    ): Variable {
        return VmGenerator::emitCreateFromCall($jit, $resumeInternalName);
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
}
