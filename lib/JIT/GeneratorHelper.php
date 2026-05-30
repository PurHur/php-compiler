<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\OpCode;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MCJIT lowering for user generators (issue #167, #3074).
 *
 * Switch-on-resume-ip for generator bodies; foreach over Generator uses this helper.
 * php-src: Zend/zend_generators.c.
 */
final class GeneratorHelper
{
    public const TARGET_PROPERTY = '__generator_resume';

    /** int64 property holding {@see __generator_state__*} bits (#3115). */
    public const STATE_PROPERTY = '__generator_state';

    private static bool $typesRegistered = false;

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
        ];
    }

    public static function registerCreator(Context $context, string $funcLc, string $resumeInternalName): void
    {
        $context->generatorCreators[strtolower($funcLc)] = $resumeInternalName;
    }

    public static function creatorResumeName(Context $context, string $funcLc): ?string
    {
        $lc = strtolower($funcLc);
        if (isset($context->generatorCreators[$lc])) {
            return $context->generatorCreators[$lc];
        }
        if (preg_match('/^(.+)\\\\([^\\\\]+)$/', $lc, $m)) {
            $short = $m[2];
            if (isset($context->generatorCreators[$short])) {
                return $context->generatorCreators[$short];
            }
        }

        return null;
    }

    public static function isGeneratorVariable(Variable $var): bool
    {
        return null !== $var->generatorStatePtr
            || null !== $var->generatorResumeName
            || $var->isJitGenerator;
    }

    /**
     * @return list<array{kind: string, op: OpCode, block: Block}>
     */
    public static function collectResumePoints(Block $block): array
    {
        $points = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_YIELD === $op->type) {
                $points[] = ['kind' => 'yield', 'op' => $op, 'block' => $block];
            } elseif (OpCode::TYPE_YIELD_FROM === $op->type) {
                $points[] = ['kind' => 'yield_from', 'op' => $op, 'block' => $block];
            } elseif (OpCode::TYPE_RETURN === $op->type || OpCode::TYPE_RETURN_VOID === $op->type) {
                break;
            } elseif (
                OpCode::TYPE_TRY === $op->type
                || OpCode::TYPE_CATCH === $op->type
                || OpCode::TYPE_FINALLY === $op->type
                || OpCode::TYPE_THROW === $op->type
            ) {
                throw new \LogicException('try/catch in generator JIT is not supported yet (issue #3074)');
            }
        }

        return $points;
    }

    private static function opcodeIndex(Block $block, OpCode $target): int
    {
        foreach ($block->opCodes as $i => $op) {
            if ($op === $target) {
                return $i;
            }
        }

        throw new \LogicException('Generator resume point opcode missing from block');
    }

    /**
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     */
    private static function resumePrefixStart(Block $block, array $points, int $pointIndex): int
    {
        if (0 === $pointIndex) {
            return 0;
        }
        $prevOp = $points[$pointIndex - 1]['op'];

        return self::opcodeIndex($block, $prevOp) + 1;
    }

    private static function compileYieldPrefix(
        \PHPCompiler\JIT $jit,
        \PHPLLVM\Value\Function_ $func,
        Block $block,
        int $startIndex,
        int $yieldIdx,
        \PHPLLVM\BasicBlock $caseBlock
    ): void {
        if ($startIndex >= $yieldIdx) {
            return;
        }
        $context = $jit->context;
        $savedStorage = $context->scope->blockStorage;
        $context->scope->blockStorage = new \SplObjectStorage();
        $exit = $jit->compileGeneratorResumePrefix($func, $block, $startIndex, $yieldIdx, $caseBlock);
        $context->builder->positionAtEnd($exit);
        $context->scope->blockStorage = $savedStorage;
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

        for ($i = 0; $i < $n; ++$i) {
            $context->builder->positionAtEnd($caseBlocks[$i]);
            $point = $points[$i];
            $pointBlock = $point['block'];
            $yieldIdx = self::opcodeIndex($pointBlock, $point['op']);
            $prefixStart = self::resumePrefixStart($pointBlock, $points, $i);
            if ('yield' === $point['kind']) {
                if ($prefixStart < $yieldIdx) {
                    self::compileYieldPrefix(
                        $jit,
                        $func,
                        $pointBlock,
                        $prefixStart,
                        $yieldIdx,
                        $caseBlocks[$i]
                    );
                }
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
        for ($i = 0; $i < $yieldFromIndex; ++$i) {
            $type = $block->opCodes[$i]->type;
            if (OpCode::TYPE_YIELD === $type || OpCode::TYPE_YIELD_FROM === $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * When yield from delegates to a nested generator call (yield from inner()), return inner resume name.
     */
    public static function resolveYieldFromGeneratorResumeName(
        Block $block,
        OpCode $yieldFromOp,
        Context $context
    ): ?string {
        $yfIdx = null;
        foreach ($block->opCodes as $i => $op) {
            if ($op === $yieldFromOp) {
                $yfIdx = $i;
                break;
            }
        }
        if (null === $yfIdx || !self::prefixOpcodesSafeForYieldFromInit($block, $yfIdx)) {
            return null;
        }
        for ($i = $yfIdx - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT !== $op->type) {
                continue;
            }
            $nameOp = $block->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                return null;
            }

            return self::creatorResumeName($context, strtolower($nameOp->value));
        }

        return null;
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

        $activeField = $context->builder->structGep($stateParam, $map['yield_from_active']);
        $htField = $context->builder->structGep($stateParam, $map['yield_from_ht']);
        $idxField = $context->builder->structGep($stateParam, $map['yield_from_idx']);
        $isGenField = $context->builder->structGep($stateParam, $map['yield_from_is_generator']);
        $active = $context->builder->load($activeField);

        $initBb = $fn->appendBasicBlock('gen_yf_init');
        $dispatchBb = $fn->appendBasicBlock('gen_yf_dispatch');
        $arrayInitBb = $fn->appendBasicBlock('gen_yf_init_array');
        $arrayIterBb = $fn->appendBasicBlock('gen_yf_iter_array');
        $genIterBb = $fn->appendBasicBlock('gen_yf_iter_gen');
        $context->builder->branchIf($active, $dispatchBb, $initBb);

        $context->builder->positionAtEnd($initBb);
        $containerVar = $jit->compileGeneratorYieldFromSetup($fn, $block, $initBb, $op, $innerResumeName);
        $effectiveResumeName = $innerResumeName ?? $containerVar->generatorResumeName;
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
            $context->builder->store($i1->constInt(1, false), $activeField);
            self::resetGeneratorStateInPlace($context, $innerState);
            $context->builder->branch($genIterBb);
        } elseif (
            ($containerVar->type & Variable::IS_NATIVE_ARRAY)
            || Variable::TYPE_HASHTABLE === $containerVar->type
            || Variable::TYPE_VALUE === $containerVar->type
        ) {
            $context->builder->branch($arrayInitBb);
            $context->builder->positionAtEnd($arrayInitBb);
            if ($containerVar->type & Variable::IS_NATIVE_ARRAY) {
                $htPtr = HashTableHelper::materializeNativeArrayForCall($context, $containerVar);
            } elseif (Variable::TYPE_HASHTABLE === $containerVar->type) {
                $htPtr = $context->helper->loadValue(HashTableHelper::asDetachedHashtable($context, $containerVar));
            } else {
                $htPtr = $context->builder->call(
                    $context->lookupFunction('__value__readHashtable'),
                    JitValueBox::valuePtrFromVariable($context, $containerVar)
                );
            }
            $context->builder->store($htPtr, $htField);
            $context->builder->store($invalidIdx, $idxField);
            $context->builder->store($i1->constInt(0, false), $isGenField);
            $context->builder->store($i1->constInt(1, false), $activeField);
            $context->builder->branch($arrayIterBb);
        } else {
            throw new \LogicException('yield from in JIT requires array or Generator container (issue #3074)');
        }

        $context->builder->positionAtEnd($dispatchBb);
        $isGen = $context->builder->load($isGenField);
        $context->builder->branchIf($isGen, $genIterBb, $arrayIterBb);

        $context->builder->positionAtEnd($genIterBb);
        if (null !== $effectiveResumeName) {
            self::emitYieldFromGeneratorIter(
                $context,
                $stateParam,
                $htField,
                $activeField,
                $isGenField,
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
            $resumeIp,
            $fn,
            $arrayIterBb
        );
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
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_active']));
        $context->builder->store($htPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_ht']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['yield_from_idx']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_generator']));
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
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_active']));
        $context->builder->store($htPtrTy->constNull(), $context->builder->structGep($statePtr, $map['yield_from_ht']));
        $context->builder->store($zero, $context->builder->structGep($statePtr, $map['yield_from_idx']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($statePtr, $map['yield_from_is_generator']));
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
        $done = $context->builder->load($context->builder->structGep($state, $map['done']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $early = $fn->appendBasicBlock('gen_iter_done');
        $body = $fn->appendBasicBlock('gen_iter_resume');
        $merge = $fn->appendBasicBlock('gen_iter_merge');
        $context->builder->branchIf($done, $early, $body);
        $context->builder->positionAtEnd($early);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($body);
        $resumeFn = $context->functions[strtolower($gen->generatorResumeName)] ?? null;
        if (!$resumeFn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('Generator resume function missing from JIT context');
        }
        $yielded = $context->builder->call($resumeFn, $state);
        $has = $context->builder->icmp(
            Builder::INT_NE,
            $yielded,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $early);
        $phi->addIncoming($has, $body);

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

    public static function compileIterReset(Context $context, Variable $gen): void
    {
        if (null === $gen->generatorStatePtr) {
            return;
        }
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $context->builder->structGep($state, $map['resume_ip']));
        $context->builder->store($zero, $context->builder->structGep($state, $map['auto_key']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['has_current']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['done']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['yield_from_active']));
        $context->builder->store($htPtrTy->constNull(), $context->builder->structGep($state, $map['yield_from_ht']));
        $context->builder->store($zero, $context->builder->structGep($state, $map['yield_from_idx']));
        $context->builder->store($i1->constInt(0, false), $context->builder->structGep($state, $map['yield_from_is_generator']));
    }

    private static function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if ('main' === $sanitized || '__init__' === $sanitized || '__shutdown__' === $sanitized) {
            return 'php_user_'.$sanitized;
        }

        return $sanitized;
    }
}
