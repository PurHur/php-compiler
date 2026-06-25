<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\OpCode;
use PHPCompiler\VM\GeneratorJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MCJIT lowering for user generators (issue #167, #3074).
 *
 * Switch-on-resume-ip for generator bodies; foreach over Generator uses this helper.
 * Compile-time CFG analysis: {@see GeneratorJitHelper} (#10105).
 * php-src: Zend/zend_generators.c.
 */
final class GeneratorHelper
{
    public const TARGET_PROPERTY = GeneratorJitHelper::TARGET_PROPERTY;

    /** int64 property holding {@see __generator_state__*} bits (#3115). */
    public const STATE_PROPERTY = GeneratorJitHelper::STATE_PROPERTY;

    private const YIELD_FROM_STRING_ERROR = GeneratorJitHelper::YIELD_FROM_STRING_ERROR;

    private const YIELD_FROM_TYPE_ERROR = GeneratorJitHelper::YIELD_FROM_TYPE_ERROR;

    /** zend_generators.c — foreach by-ref requires generator yields-by-ref (#4599). */
    public const FOREACH_GENERATOR_BYREF_ERROR = GeneratorJitHelper::FOREACH_GENERATOR_BYREF_ERROR;

    private static bool $typesRegistered = false;

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
        if (self::$typesRegistered) {
            return;
        }
        self::$typesRegistered = true;
        $struct = $context->context->namedStructType('__generator_state__');
        $context->registerType('__generator_state__', $struct);
        $context->registerType('__generator_state__*', $struct->pointerType(0));
        $struct->setBody(
            false,
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__hashtable__*'),
            $context->getTypeFromString('size_t'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__object__*'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('int1'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('__value__'),
            $context->getTypeFromString('int1'),
        );
        $context->structFieldMap['__generator_state__'] = [
            'resume_ip' => 0,
            'auto_key' => 1,
            'has_current' => 2,
            'done' => 3,
            'current_key' => 4,
            'current_value' => 5,
            'yield_from_active' => 6,
            'yield_from_ht' => 7,
            'yield_from_idx' => 8,
            'yield_from_is_generator' => 9,
            'yield_from_is_iterator' => 10,
            'yield_from_iter_obj' => 11,
            'yield_from_iter_advance' => 12,
            'pending_send' => 13,
            'has_pending_send' => 14,
            'has_pending_throw' => 15,
            'pending_throw' => 16,
            'return_value' => 17,
            'has_returned' => 18,
        ];
    }

    private static function clearPendingAndReturnFields(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_pending_send']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_pending_throw']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_returned']));
        foreach (['pending_send', 'pending_throw', 'return_value'] as $field) {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map[$field]))
            );
        }
    }

    private static function clearYieldFromFields(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_active']));
        $context->builder->store($htPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_ht']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['yield_from_idx']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_generator']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_iterator']));
        $context->builder->store($objPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_iter_obj']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_iter_advance']));
    }

    private static function yieldFromContainerUserType(Block $block, OpCode $op): ?string
    {
        return GeneratorJitHelper::yieldFromContainerUserType($block, $op);
    }

    private static function copyVariableToStateValueField(
        Context $context,
        Variable $src,
        Value $destField
    ): void {
        JitValueBox::copyFromPointer(
            $context,
            $destField,
            JitValueBox::valuePtrFromVariable($context, $src)
        );
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

    private static function findTrySetupForYieldBlock(Block $entry, Block $yieldBlock): ?array
    {
        return GeneratorJitHelper::findTrySetupForYieldBlock($entry, $yieldBlock);
    }

    private static function opcodeIndex(Block $block, OpCode $target): int
    {
        return GeneratorJitHelper::opcodeIndex($block, $target);
    }

    /**
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     */
    private static function resumePrefixStart(array $points, int $pointIndex): int
    {
        return GeneratorJitHelper::resumePrefixStart($points, $pointIndex);
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
        $trySetup = self::findTrySetupForYieldBlock($entry, $firstPoint['block']);
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
        $prevAfter = self::opcodeIndex($prevBlock, $prev['op']) + 1;
        if ($prevAfter < $prevBlock->nOpCodes) {
            self::compileYieldPrefix($jit, $func, $prevBlock, $prevAfter, $prevBlock->nOpCodes, $caseBlock);
        }
        $currBlock = $curr['block'];
        $currIdx = self::opcodeIndex($currBlock, $curr['op']);
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
            self::llvmInternalName($resumeName),
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
        $trySetup = [] !== $points ? self::findTrySetupForYieldBlock($block, $points[0]['block']) : null;
        if (null !== $trySetup) {
            [$handlerBlock, , $tryIndex] = $trySetup;
            $catchArms = TryCatchHelper::collectCatchOps($handlerBlock, $tryIndex);
            foreach ($points as $i => $point) {
                if (0 === $i) {
                    continue;
                }
                foreach ($catchArms as $arm) {
                    $catchBody = $arm['op']->block1;
                    if ($catchBody instanceof Block && self::cfgBlockContains($catchBody, $point['block'])) {
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
            $yieldIdx = self::opcodeIndex($pointBlock, $point['op']);
            $prefixEntry = $caseBlocks[$i];
            if (0 === $i && $pointBlock !== $block) {
                $prefixEntry = self::compileEntryLeadIn($jit, $func, $block, $point, $caseBlocks[$i]);
            } elseif ($i > 0 && $points[$i - 1]['block'] !== $pointBlock) {
                self::compileCrossBlockResumePrefix($jit, $func, $points[$i - 1], $point, $caseBlocks[$i]);
            }
            $catchDispatchBb = $context->generatorCatchDispatchEntry[spl_object_id($pointBlock)] ?? null;
            $prefixStart = self::resumePrefixStart($points, $i);
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
                self::emitYieldPoint($jit, $pointBlock, $point['op'], $stateParam, $i + 1);
            } else {
                self::emitYieldFromPoint(
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

    private static function emitYieldPoint(
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

    private static function emitYieldFromPoint(
        \PHPCompiler\JIT $jit,
        Block $block,
        OpCode $op,
        Value $stateParam,
        int $resumeIp
    ): void {
        $context = $jit->context;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $invalidIdx = $context->builder->sub($zero, $sizeT->constInt(1, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $innerResumeName = self::resolveYieldFromGeneratorResumeName($block, $op, $context);
        $containerUserType = self::yieldFromContainerUserType($block, $op);
        $supportsIterator = false;
        if (null !== $op->arg2) {
            try {
                $previewVar = $context->getVariableFromOp($block->getOperand($op->arg2));
                $supportsIterator = IteratorProtocolHelper::canLowerIteratorProtocol(
                    $context,
                    $previewVar,
                    $containerUserType
                );
            } catch (\LogicException) {
                $supportsIterator = false;
            }
        }

        $activeField = $context->builder->structGep($stateParam, $map['yield_from_active']);
        $htField = $context->builder->structGep($stateParam, $map['yield_from_ht']);
        $idxField = $context->builder->structGep($stateParam, $map['yield_from_idx']);
        $isGenField = $context->builder->structGep($stateParam, $map['yield_from_is_generator']);
        $isIterField = $context->builder->structGep($stateParam, $map['yield_from_is_iterator']);
        $active = $context->builder->load($activeField);

        $initBb = $fn->appendBasicBlock('gen_yf_init');
        $dispatchBb = $fn->appendBasicBlock('gen_yf_dispatch');
        $arrayIterBb = $fn->appendBasicBlock('gen_yf_iter_array');
        $genIterBb = $fn->appendBasicBlock('gen_yf_iter_gen');
        $iterIterBb = $supportsIterator ? $fn->appendBasicBlock('gen_yf_iter_obj') : null;
        $context->builder->branchIf($active, $dispatchBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $points = self::collectResumePoints($block);
        $prefixStart = self::resumePrefixStart($points, $resumeIp);
        $containerVar = $jit->compileGeneratorYieldFromSetup(
            $fn,
            $block,
            $initBb,
            $op,
            $innerResumeName,
            $prefixStart
        );
        $effectiveResumeName = $innerResumeName ?? $containerVar->generatorResumeName;
        $i64 = $context->getTypeFromString('int64');
        $invalidContainerBb = $fn->appendBasicBlock('gen_yf_invalid_container');
        if (
            null !== $effectiveResumeName
            && self::isGeneratorVariable($containerVar)
            && null !== $containerVar->generatorStatePtr
        ) {
            $innerState = $containerVar->generatorStatePtr;
            $context->builder->store(
                self::castGeneratorStateToHtPtr($context, $innerState),
                $htField
            );
            $context->builder->store($i1->constInt(1, false), $isGenField);
            $context->builder->store($i1->constInt(0, false), $isIterField);
            $context->builder->store($i1->constInt(1, false), $activeField);
            self::resetGeneratorStateInPlace($context, $innerState);
            $context->builder->branch($genIterBb);
        } elseif (Variable::TYPE_STRING === $containerVar->type) {
            $context->builder->branch($invalidContainerBb);
        } elseif (
            ($containerVar->type & Variable::IS_NATIVE_ARRAY)
            || Variable::TYPE_HASHTABLE === $containerVar->type
        ) {
            if ($containerVar->type & Variable::IS_NATIVE_ARRAY) {
                $htPtr = HashTableHelper::materializeNativeArrayForCall($context, $containerVar);
            } else {
                $htPtr = $context->helper->loadValue(HashTableHelper::asDetachedHashtable($context, $containerVar));
            }
            $context->builder->store($htPtr, $htField);
            $context->builder->store($invalidIdx, $idxField);
            $context->builder->store($i1->constInt(0, false), $isGenField);
            $context->builder->store($i1->constInt(0, false), $isIterField);
            $context->builder->store($i1->constInt(1, false), $activeField);
            $context->builder->branch($arrayIterBb);
        } elseif (
            Variable::TYPE_VALUE === $containerVar->type
            && !IteratorProtocolHelper::canLowerIteratorProtocol($context, $containerVar, $containerUserType)
        ) {
            self::compileYieldFromValueBoxContainerInit(
                $context,
                $fn,
                $containerVar,
                $stateParam,
                $map,
                $htField,
                $idxField,
                $activeField,
                $isGenField,
                $isIterField,
                $invalidIdx,
                $arrayIterBb,
                $invalidContainerBb
            );
        } elseif (IteratorProtocolHelper::canLowerIteratorProtocol($context, $containerVar, $containerUserType)) {
            $receiver = IteratorProtocolHelper::resolveForeachReceiver(
                $context,
                $containerVar,
                $containerUserType
            );
            $obj = Variable::KIND_VALUE === $receiver->kind
                ? $receiver->value
                : $context->builder->load($receiver->value);
            $context->refcount->addref($obj);
            $context->builder->store($obj, $context->builder->structGep($stateParam, $map['yield_from_iter_obj']));
            IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind', $containerUserType);
            $context->builder->store($i1->constInt(0, false), $isGenField);
            $context->builder->store($i1->constInt(1, false), $isIterField);
            $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['yield_from_iter_advance']));
            $context->builder->store($i1->constInt(1, false), $activeField);
            $context->builder->branch($iterIterBb);
        } else {
            throw new \LogicException('yield from in JIT requires array, Generator, or Iterator container (issue #4562)');
        }

        $context->builder->positionAtEnd($invalidContainerBb);
        self::emitYieldFromStringErrorAndFinish($context, $stateParam, $map, $i1, $i64);

        $context->builder->positionAtEnd($dispatchBb);
        $isGen = $context->builder->load($isGenField);
        if ($supportsIterator) {
            $isIter = $context->builder->load($isIterField);
            $notGenBb = $fn->appendBasicBlock('gen_yf_not_gen');
            $context->builder->branchIf($isGen, $genIterBb, $notGenBb);
            $context->builder->positionAtEnd($notGenBb);
            $context->builder->branchIf($isIter, $iterIterBb, $arrayIterBb);
        } else {
            $context->builder->branchIf($isGen, $genIterBb, $arrayIterBb);
        }

        $context->builder->positionAtEnd($genIterBb);
        if (null !== $effectiveResumeName) {
            self::emitYieldFromGeneratorIter(
                $context,
                $stateParam,
                $htField,
                $activeField,
                $isGenField,
                $isIterField,
                $effectiveResumeName,
                $resumeIp,
                $fn
            );
        } else {
            $context->builder->returnValue($context->getTypeFromString('int64')->constInt(0, false));
        }

        $context->builder->positionAtEnd($arrayIterBb);
        self::emitYieldFromArrayIter(
            $context,
            $stateParam,
            $htField,
            $idxField,
            $activeField,
            $isGenField,
            $isIterField,
            $resumeIp,
            $fn,
            $arrayIterBb
        );

        if ($supportsIterator && null !== $iterIterBb) {
            $context->builder->positionAtEnd($iterIterBb);
            self::emitYieldFromIteratorIter(
                $context,
                $stateParam,
                $activeField,
                $isGenField,
                $isIterField,
                $containerUserType,
                $resumeIp,
                $fn
            );
        }
    }

    private static function castGeneratorStateToHtPtr(Context $context, Value $statePtr): Value
    {
        return $context->builder->pointerCast(
            $statePtr,
            $context->getTypeFromString('__hashtable__*')
        );
    }

    private static function loadYieldFromGeneratorState(Context $context, Value $htField): Value
    {
        $loaded = $context->builder->load($htField);

        return $context->builder->pointerCast(
            $loaded,
            $context->getTypeFromString('__generator_state__*')
        );
    }

    private static function emitYieldFromGeneratorIter(
        Context $context,
        Value $stateParam,
        Value $htField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        string $innerResumeName,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $innerState = self::loadYieldFromGeneratorState($context, $htField);
        $resumeFn = $context->functions[strtolower($innerResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing for yield from: '.$innerResumeName);
        }
        $yielded = $context->builder->call($resumeFn, $innerState);
        $hasYield = $context->builder->icmp(
            Builder::INT_NE,
            $yielded,
            $i64->constInt(0, false)
        );
        $yieldBb = $fn->appendBasicBlock('gen_yf_gen_yield');
        $exhausted = $fn->appendBasicBlock('gen_yf_gen_exhausted');
        $context->builder->branchIf($hasYield, $yieldBb, $exhausted);

        $context->builder->positionAtEnd($yieldBb);
        self::copyCurrentFromInnerToOuter($context, $stateParam, $innerState);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromIteratorIter(
        Context $context,
        Value $stateParam,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        ?string $containerUserType,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $objField = $context->builder->structGep($stateParam, $map['yield_from_iter_obj']);
        $advanceField = $context->builder->structGep($stateParam, $map['yield_from_iter_advance']);
        $obj = $context->builder->load($objField);
        $receiver = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);

        $maybeNext = $fn->appendBasicBlock('gen_yf_iter_maybe_next');
        $checkValid = $fn->appendBasicBlock('gen_yf_iter_valid');
        $yieldBb = $fn->appendBasicBlock('gen_yf_iter_yield');
        $exhausted = $fn->appendBasicBlock('gen_yf_iter_exhausted');

        $needsNext = $context->builder->load($advanceField);
        $context->builder->branchIf($needsNext, $maybeNext, $checkValid);

        $context->builder->positionAtEnd($maybeNext);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next', $containerUserType);
        $context->builder->store($i1->constInt(0, false), $advanceField);
        $context->builder->branch($checkValid);

        $context->builder->positionAtEnd($checkValid);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid', $containerUserType);
        $context->builder->branchIf($valid, $yieldBb, $exhausted);

        $context->builder->positionAtEnd($yieldBb);
        $key = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'key', $containerUserType);
        $value = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'current', $containerUserType);
        self::copyVariableToStateValueField(
            $context,
            $key,
            $context->builder->structGep($stateParam, $map['current_key'])
        );
        self::copyVariableToStateValueField(
            $context,
            $value,
            $context->builder->structGep($stateParam, $map['current_value'])
        );
        $context->builder->store($i1->constInt(1, false), $advanceField);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromArrayIter(
        Context $context,
        Value $stateParam,
        Value $htField,
        Value $idxField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        int $resumeIp,
        \PHPLLVM\Value\Function_ $fn,
        \PHPLLVM\BasicBlock $iterBb
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $htMap = $context->structFieldMap['__hashtable__'];
        $one = $sizeT->constInt(1, false);

        $context->builder->positionAtEnd($iterBb);
        $idx = $context->builder->load($idxField);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->store($nextIdx, $idxField);
        $ht = $context->builder->load($htField);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $inPacked = $context->builder->icmp(Builder::INT_ULT, $nextIdx, $nextFree);

        $packedBody = $fn->appendBasicBlock('gen_yf_packed');
        $exhausted = $fn->appendBasicBlock('gen_yf_exhausted');
        $yieldBb = $fn->appendBasicBlock('gen_yf_yield');
        $advancePacked = $fn->appendBasicBlock('gen_yf_advance');
        $context->builder->branchIf($inPacked, $packedBody, $exhausted);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $nextIdx
        );
        $context->builder->branchIf($isSet, $yieldBb, $advancePacked);

        $context->builder->positionAtEnd($advancePacked);
        $context->builder->branch($iterBb);

        $context->builder->positionAtEnd($yieldBb);
        $keyField = $context->builder->structGep($stateParam, $map['current_key']);
        $valField = $context->builder->structGep($stateParam, $map['current_value']);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $keyField),
            $context->builder->truncOrBitCast($nextIdx, $context->getTypeFromString('int64'))
        );
        $values = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $entry = $context->builder->inBoundsGep($values, $nextIdx);
        JitValueBox::copyFromPointer($context, $valField, $entry);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->store($sizeT->constInt($resumeIp, false), $context->builder->structGep($stateParam, $map['resume_ip']));
        $context->builder->returnValue($i64->constInt(1, false));

        $context->builder->positionAtEnd($exhausted);
        $context->builder->store($i1->constInt(0, false), $activeField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store(
            $sizeT->constInt($resumeIp + 1, false),
            $context->builder->structGep($stateParam, $map['resume_ip'])
        );
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function resetGeneratorStateInPlace(Context $context, Value $statePtr): void
    {
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        self::clearYieldFromFields($context, $statePtr);
    }

    private static function copyCurrentFromInnerToOuter(
        Context $context,
        Value $outerState,
        Value $innerState
    ): void {
        $map = $context->structFieldMap['__generator_state__'];
        $outerKey = $context->builder->structGep($outerState, $map['current_key']);
        $innerKey = $context->builder->structGep($innerState, $map['current_key']);
        JitValueBox::copyFromPointer($context, $outerKey, $innerKey);
        $outerVal = $context->builder->structGep($outerState, $map['current_value']);
        $innerVal = $context->builder->structGep($innerState, $map['current_value']);
        JitValueBox::copyFromPointer($context, $outerVal, $innerVal);
    }

    public static function emitCreateFromCall(
        \PHPCompiler\JIT $jit,
        string $resumeInternalName
    ): Variable {
        $context = $jit->context;
        self::ensureTypes($context);
        $stateTy = $context->getTypeFromString('__generator_state__');
        $statePtr = $context->memory->malloc($stateTy);
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        self::clearYieldFromFields($context, $statePtr);
        self::clearPendingAndReturnFields($context, $statePtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_key']))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $context->builder->structGep($statePtr, $map['current_value']))
        );

        $classId = $context->type->object->lookup('Generator');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        self::storeResumeName($context, $obj, $resumeInternalName);
        $stateBits = $context->builder->ptrtoint(
            $statePtr,
            $context->getTypeFromString('int64')
        );
        $stateBitsVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $stateBits);
        $context->type->object->storeInstanceProperty($obj, 'Generator', self::STATE_PROPERTY, $stateBitsVar);

        $var = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $var->generatorStatePtr = $statePtr;
        $var->generatorResumeName = $resumeInternalName;
        $var->isJitGenerator = true;

        return $var;
    }

    private static function storeResumeName(Context $context, Value $obj, string $resumeName): void
    {
        $targetStr = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(strtolower($resumeName)))
        );
        $targetStr->addref();
        $context->type->object->storeInstanceProperty(
            $obj,
            'Generator',
            self::TARGET_PROPERTY,
            $targetStr
        );
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

    private static function emitForeachGeneratorByRefError(Context $context, ?\PHPCompiler\JIT $jit): void
    {
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'Exception',
                self::FOREACH_GENERATOR_BYREF_ERROR,
                $jit
            );

            return;
        }
        TryCatchHelper::emitCatchableClassError($context, 'Exception', self::FOREACH_GENERATOR_BYREF_ERROR);
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
        self::clearYieldFromFields($context, $state);
        self::clearPendingAndReturnFields($context, $state);
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
        FiberHelper::assignValueField($context, $destField, $src, $srcOp);
    }

    private static function emitYieldFromStringErrorAndFinish(
        Context $context,
        Value $stateParam,
        array $map,
        \PHPLLVM\Type\IntegerType $i1,
        \PHPLLVM\Type\IntegerType $i64
    ): void {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::YIELD_FROM_STRING_ERROR);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function emitYieldFromTypeErrorAndFinish(
        Context $context,
        Value $stateParam,
        array $map,
        \PHPLLVM\Type\IntegerType $i1,
        \PHPLLVM\Type\IntegerType $i64
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::YIELD_FROM_TYPE_ERROR);
        $context->builder->store($i1->constInt(1, false), $context->builder->structGep($stateParam, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($stateParam, $map['has_current']));
        $context->builder->returnValue($i64->constInt(0, false));
    }

    private static function compileYieldFromValueBoxContainerInit(
        Context $context,
        \PHPLLVM\Value\Function_ $fn,
        Variable $containerVar,
        Value $stateParam,
        array $map,
        Value $htField,
        Value $idxField,
        Value $activeField,
        Value $isGenField,
        Value $isIterField,
        Value $invalidIdx,
        \PHPLLVM\BasicBlock $arrayIterBb,
        \PHPLLVM\BasicBlock $invalidContainerBb
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $containerVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $tag = 'u'.(string) spl_object_id($context);

        $arrayBb = $fn->appendBasicBlock('gen_yf_vb_array_'.$tag);
        $stringBb = $fn->appendBasicBlock('gen_yf_vb_string_'.$tag);
        $failBb = $fn->appendBasicBlock('gen_yf_vb_fail_'.$tag);
        $afterArray = $fn->appendBasicBlock('gen_yf_vb_after_array_'.$tag);

        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ARRAY, false)
        );
        $context->builder->branchIf($isArray, $arrayBb, $afterArray);

        $context->builder->positionAtEnd($afterArray);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $failBb);

        $context->builder->positionAtEnd($arrayBb);
        $htPtr = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->store($htPtr, $htField);
        $context->builder->store($invalidIdx, $idxField);
        $context->builder->store($i1->constInt(0, false), $isGenField);
        $context->builder->store($i1->constInt(0, false), $isIterField);
        $context->builder->store($i1->constInt(1, false), $activeField);
        $context->builder->branch($arrayIterBb);

        $context->builder->positionAtEnd($stringBb);
        $context->builder->branch($invalidContainerBb);

        $context->builder->positionAtEnd($failBb);
        self::emitYieldFromStringErrorAndFinish($context, $stateParam, $map, $i1, $context->getTypeFromString('int64'));
    }

    private static function llvmInternalName(string $name): string
    {
        return GeneratorJitHelper::llvmInternalName($name);
    }
}
