<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\JIT\OperandName;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Call-arg send compilation (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m). Null-literal / hoisted
 * property-const / coalesce early valueSlot wiring lives in
 * {@see CallArgNullLiteralHoistedPropertyConstAndCoalesceValueSlots}; dimFetch /
 * inline Array_|FuncCall valueSlot wiring in
 * {@see CallArgDimFetchAndInlineArrayLiteralValueSlotWire}; residual encapsed /
 * concat / arithmetic / named-local / preceding-producer early slots in
 * {@see CallArgEncapsedConcatArithmeticNamedLocalAndPrecedingProducerValueSlots};
 * residual dim / coalesce / New_ / hoisted-fold / adjacent-producer slots in
 * {@see CallArgResidualDimCoalesceNewHoistedFoldAndAdjacentProducerValueSlots};
 * closure / dead-temp / producer-remap / eval / prefer-named-local slots in
 * {@see CallArgClosureDeadTempProducerEvalAndPreferNamedLocalValueSlots};
 * multi-producer / named-local / array_column / in_array family / array_pad slots in
 * {@see CallArgMultiProducerNamedLocalArrayColumnSearchPadValueSlots};
 * sibling / array_merge / embedded / isset / logical / named-assign slots in
 * {@see CallArgSiblingMergeEmbeddedIssetLogicalNamedAssignValueSlots};
 * dead-array / comparison / concat / pointer / var_export slots in
 * {@see CallArgDeadArrayComparisonConcatPointerValueSlots};
 * filter_var / json_decode / array_merge|map|filter / preg_split / explode slots in
 * {@see CallArgFilterJsonDecodeMergeMapFilterSplitExplodeValueSlots};
 * dim-adjacent / filter_input / array_map callback / array_merge / andPhi /
 * array_multisort early valueSlot wiring lives in
 * {@see CallArgDimAdjacentFilterInputMapMergeLogicalMultisortValueSlots};
 * assign-in-call / proc_open / ternary / haystack / slice / chained merge / IIFE
 * early valueSlot wiring lives in
 * {@see CallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots};
 * array_column / combine / coalesce / stream_context / filter family / spaceship in
 * {@see CallArgColumnCombineCoalesceStreamFilterSpaceshipAndFilterFamilyValueSlots};
 * nested adjacent / sibling / var_export / combine|merge force / const-prelude /
 * forced-sibling slots in
 * {@see CallArgNestedAdjacentSiblingVarExportMergeForceConstPreludeValueSlots};
 * inline Array_ unpack / array_reduce / family resolve in
 * {@see CallArgInlineArrayUnpackReduceAndFamilyResolve}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CompileCallArgSends
{
    protected function compileCallArgSends(
        array $args,
        Block $block,
        ?string $calleeName = null,
        ?Op $cfgCallOp = null
    ): array
    {
        $this->validateCallArgOrder($args);

        if (null !== $cfgCallOp && null !== $block->orig) {
            $this->ensureCfgChildrenOpIndicesBuilt($block->orig->children, $block->orig);
        }

        if (null !== $cfgCallOp) {
            $explodeSends = $this->compileExplodeLeadingConstFetchFuncCallInlineCallArgSends($args, $block, $cfgCallOp);
            if (null !== $explodeSends) {
                return $explodeSends;
            }
            $arrayChunkSends = $this->compileArrayChunkInlineNestedCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayChunkSends) {
                return $arrayChunkSends;
            }
            $iteratorToArraySends = $this->compileIteratorToArrayInlineNewPreserveKeysCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $iteratorToArraySends) {
                return $iteratorToArraySends;
            }
            $arrayChunkEnumLengthSends = $this->compileArrayChunkInlineArrayClassConstArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayChunkEnumLengthSends) {
                return $arrayChunkEnumLengthSends;
            }
            $arrayPadSends = $this->compileArrayPadInlineHaystackCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadSends) {
                return $arrayPadSends;
            }
            $arrayPadPadTypeSends = $this->compileArrayPadInlinePadTypeEnumCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadPadTypeSends) {
                return $arrayPadPadTypeSends;
            }
            $arrayPadEnumLengthSends = $this->compileArrayPadInlineArrayClassConstLengthCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadEnumLengthSends) {
                return $arrayPadEnumLengthSends;
            }
            $unpackPackEnumSends = $this->compileUnpackInlinePackEnumOffsetCallArgSends($args, $block, $cfgCallOp);
            if (null !== $unpackPackEnumSends) {
                return $unpackPackEnumSends;
            }
            $extractSends = $this->compileExtractInlineMultiArgCallArgSends($args, $block, $cfgCallOp);
            if (null !== $extractSends) {
                return $extractSends;
            }
            $dateSunSends = $this->compileDateSunFuncInlineCallArgSends($args, $block, $cfgCallOp);
            if (null !== $dateSunSends) {
                return $dateSunSends;
            }
            $arrayWalkSends = $this->compileArrayWalkInlineNewClosureCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayWalkSends) {
                return $arrayWalkSends;
            }
            $inlineClosurePairSends = $this->compileInlineClosurePairHaystackCallbackCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $inlineClosurePairSends) {
                return $inlineClosurePairSends;
            }
            $trailingComparatorAssignSends = $this->compileTrailingComparatorInlineAssignCallbackCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $trailingComparatorAssignSends) {
                return $trailingComparatorAssignSends;
            }
            $this->ensureDeferredSiblingInlineCallArgProducersCompiled($block, $cfgCallOp);
        }

        $inlineProducerCfgChildren = $this->inlineCallArgProducerCfgChildren($block);

        $sends = [];
        foreach ($args as $argIndex => $arg) {
            // Zend zend_compile.c: by-ref call args cannot bind temporary lit-dim / new-prop (#29522).
            $cfgArg = null;
            if (
                null !== $cfgCallOp
                && \is_array($cfgCallOp->args ?? null)
                && \array_key_exists((int) $argIndex, $cfgCallOp->args)
            ) {
                $cfgArg = $cfgCallOp->args[(int) $argIndex];
            }
            $byRefProbe = $cfgArg instanceof Operand ? $cfgArg : $arg;
            if (
                null !== $calleeName
                && $byRefProbe instanceof Operand
                && $this->callArgRequiresByRef($calleeName, (int) $argIndex, $byRefProbe, $block)
            ) {
                $this->rejectTemporaryByRefCallArg($byRefProbe, $block, $cfgCallOp instanceof Op ? $cfgCallOp : null);
            }
            $nameSlot = $this->callArgNameSlot($arg, $block);
            $unpackFlag = $this->callArgUnpack($arg) ? 1 : null;
            $earlyFastSend = $this->tryCompileCallArgEarlyFastPathAndMixedPropertyFetchSend(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $cfgArg instanceof Operand ? $cfgArg : null,
                $nameSlot,
                $unpackFlag,
                $sends
            );
            if (null !== $earlyFastSend) {
                $sends[] = $earlyFastSend;
                continue;
            }
            $preludeFastSend = $this->tryCompileCallArgNullMergePropertyFetchAndHoistedPreludeSend(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $nameSlot,
                $unpackFlag,
                $args,
                $sends
            );
            if (null !== $preludeFastSend) {
                $sends[] = $preludeFastSend;
                continue;
            }
            $inlineArrayLiteralArgWired = false;
            $outerMultiArraySetOpArgWired = false;
            if ($this->tryCompileCallArgInlineEnumCastErrorSuppressAndFccSend(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $args,
                $nameSlot,
                $unpackFlag,
                $sends
            )) {
                continue;
            }
            // O(1) via Block cache — was a full opCodes scan per arg (#36387).
            $callOrdinal = $block->funccallInitCount();
            $dimFetchSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if ($this->tryCompileCallArgPostFccDimCoalesceExprPreludeAndNestedNewSend(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $args,
                $nameSlot,
                $unpackFlag,
                $dimFetchSlot,
                $sends
            )) {
                continue;
            }
            $inlineArray = null;
            $arrayCombineNestedFuncArg = false;
            $arrayMergeNestedFuncArg = false;
            if ($this->tryCompileCallArgInlineArrayUnpackReduceAndFamilyResolve(
                $arg,
                (int) $argIndex,
                $block,
                $cfgCallOp,
                $args,
                $nameSlot,
                $unpackFlag,
                $dimFetchSlot,
                $sends,
                $inlineArray,
                $arrayCombineNestedFuncArg,
                $arrayMergeNestedFuncArg
            )) {
                continue;
            }
            $callArgOperand = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            $prefetchOps = [];
            $assignedNamedLocal = null;
            $valueSlot = null;
            $nullLiteralCallArgSlot = null;
            $hoistedEnumPropertyCallArgSlotWired = false;
            $this->resolveCallArgNullLiteralHoistedPropertyConstAndCoalesceValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $cfgCallOp,
                $callArgOperand,
                $sends,
                $valueSlot,
                $nullLiteralCallArgSlot,
                $hoistedEnumPropertyCallArgSlotWired
            );
            $tookDimOrInlineArrayBranch = false;
            $this->wireCallArgDimFetchAndInlineArrayLiteralValueSlot(
                $arg,
                (int) $argIndex,
                $block,
                $cfgCallOp,
                $callArgOperand,
                $dimFetchSlot,
                $unpackFlag,
                $sends,
                $valueSlot,
                $inlineArray,
                $inlineArrayLiteralArgWired,
                $tookDimOrInlineArrayBranch
            );
            if (!$tookDimOrInlineArrayBranch) {
                $this->resolveCallArgEncapsedConcatArithmeticNamedLocalAndPrecedingProducerValueSlots(
                    $arg,
                    (int) $argIndex,
                    $block,
                    $calleeName,
                    $cfgCallOp,
                    $sends,
                    $valueSlot,
                    $assignedNamedLocal,
                    $inlineArrayLiteralArgWired
                );
                $this->resolveCallArgResidualDimCoalesceNewHoistedFoldAndAdjacentProducerValueSlots(
                    $arg,
                    (int) $argIndex,
                    $block,
                    $calleeName,
                    $cfgCallOp,
                    $inlineProducerCfgChildren,
                    $callOrdinal,
                    $sends,
                    $valueSlot
                );
                $this->resolveCallArgClosureDeadTempProducerEvalAndPreferNamedLocalValueSlots(
                    $arg,
                    (int) $argIndex,
                    $block,
                    $calleeName,
                    $cfgCallOp,
                    $sends,
                    $valueSlot,
                    $assignedNamedLocal,
                    $inlineArrayLiteralArgWired,
                    $hoistedEnumPropertyCallArgSlotWired
                );
            }
            if ([] !== $prefetchOps) {
                $sends = array_merge($sends, $prefetchOps);
            }
            $this->resolveCallArgMultiProducerNamedLocalArrayColumnSearchPadValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $sends,
                $valueSlot,
                $assignedNamedLocal,
                $inlineArrayLiteralArgWired
            );
            foreach ($this->tryEmitAdjacentAssignForInlineCallArg(
                $arg,
                null !== $valueSlot ? (string) $valueSlot : null,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) as $assignOp) {
                $sends[] = $assignOp;
            }
            $namedAssignDest = null;
            $this->resolveCallArgSiblingMergeEmbeddedIssetLogicalNamedAssignValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $unpackFlag,
                $dimFetchSlot,
                $nameSlot,
                $sends,
                $valueSlot,
                $namedAssignDest,
                $inlineArrayLiteralArgWired
            );
            $this->resolveCallArgDeadArrayComparisonConcatPointerValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $dimFetchSlot,
                $namedAssignDest,
                $inlineArrayLiteralArgWired,
                $sends,
                $valueSlot
            );
            $this->resolveCallArgFilterJsonDecodeMergeMapFilterSplitExplodeValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $sends,
                $valueSlot
            );
            $this->resolveCallArgDimAdjacentFilterInputMapMergeLogicalMultisortValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $hoistedEnumPropertyCallArgSlotWired,
                $sends,
                $valueSlot,
                $inlineArrayLiteralArgWired
            );

            $arraySliceSlot = null;
            $this->resolveCallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $callArgOperand ?? $arg,
                $inlineArray,
                $inlineArrayLiteralArgWired,
                $hoistedEnumPropertyCallArgSlotWired,
                $sends,
                $valueSlot,
                $outerMultiArraySetOpArgWired,
                $arraySliceSlot
            );

            $this->resolveCallArgColumnCombineCoalesceStreamFilterSpaceshipAndFilterFamilyValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $sends,
                $valueSlot
            );

            $this->resolveCallArgNestedAdjacentSiblingVarExportMergeForceConstPreludeValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $arraySliceSlot,
                $inlineArrayLiteralArgWired,
                $hoistedEnumPropertyCallArgSlotWired,
                $sends,
                $valueSlot
            );
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_intersect', 'array_diff', 'array_intersect_key', 'array_diff_key'],
                    true
                )
                && $this->countDeadArrayInlineCallArgs($cfgCallOp) >= 2
            ) {
                $multiOuterExec = $this->outerSiblingInlineCallArgProducerExecReturnSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $multiOuterExec) {
                    $valueSlot = $multiOuterExec;
                }
            }
            $literalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if ($this->isEmbeddedCallLiteralArg($literalProbe)) {
                // Must run after sibling/adjacent wiring — do not alias prior EXEC_RETURN (#16254, array_slice #10229).
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            $sendProbe = $literalProbe;
            $sendName = Block::resolveVariableName($sendProbe);
            if (null !== $sendName && '' !== $sendName) {
                $paramSlot = $block->paramSlotForName($sendName);
                if (null !== $paramSlot) {
                    $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, $paramSlot, true);
                }
            }
            $postSuppressAssignSlot = $this->slotForPostErrorSuppressAssignNamedLocalCallArg($sendProbe, $block);
            if (null !== $postSuppressAssignSlot) {
                $valueSlot = (string) $postSuppressAssignSlot;
            }
            // substr(sprintf('%o', fileperms($path)), -N) — adjacent nested wiring must not clobber named path locals (#13636, #16055).
            $sendNamedLocalSlot = $this->namedLocalCallArgSlotIfBound(
                $sendProbe,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) ?? $this->slotForNamedLocalFromAssignVarOperand($sendProbe, $block);
            if (null !== $sendNamedLocalSlot) {
                if (
                    $this->callArgIsDeadInlineTemporary($sendProbe)
                    && null !== $valueSlot
                    && null !== $cfgCallOp
                    && \count($cfgCallOp->args ?? []) >= 2
                    && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                ) {
                    // Keep sibling/nested EXEC_RETURN wiring — do not remap dead temps to $path locals (#16480).
                } else {
                    $sendNamedAssignDest = $block->slotForNamedAssignDest($sendProbe);
                    $valueSlot = null !== $sendNamedAssignDest
                        ? $this->resolveNamedAssignCallArgSlot(
                            $block,
                            (int) $sendNamedAssignDest,
                            $calleeName,
                            (int) $argIndex,
                            $sendProbe
                        )
                        : (string) $this->finalizeOperandSlotForAccess($block, (int) $sendNamedLocalSlot, true);
                }
            }
            // probe('label', in_array(...)) — lone nested callee EXEC_RETURN, not strict/haystack operand (#16312).
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \is_array($cfgCallOp->args ?? null)
                && $this->callArgIsDeadInlineTemporary($sendProbe)
            ) {
                $deadTempCount = 0;
                foreach ($cfgCallOp->args as $deadArg) {
                    if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                        ++$deadTempCount;
                    }
                }
                if (1 === $deadTempCount) {
                    $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (\is_int($callIndex) && $callIndex > 0) {
                        $adjacentIndex = $callIndex - 1;
                        while ($adjacentIndex >= 0) {
                            $skip = $block->orig->children[$adjacentIndex] ?? null;
                            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                                --$adjacentIndex;
                                continue;
                            }
                            break;
                        }
                        $adjacent = $block->orig->children[$adjacentIndex] ?? null;
                        if (
                            ($adjacent instanceof Op\Expr\FuncCall || $adjacent instanceof Op\Expr\NsFuncCall)
                            && $this->isAdjacentNestedFuncCallProducer(
                                $adjacent,
                                $cfgCallOp,
                                $adjacentIndex,
                                $callIndex
                            )
                        ) {
                            $singleNestedExec = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($sends)
                                ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
                            if (null !== $singleNestedExec) {
                                $valueSlot = (string) $singleNestedExec;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
                && 'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $this->callArgIsDeadInlineTemporary($sendProbe)
            ) {
                $padHaystackSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                if (null !== $padHaystackSlot) {
                    $valueSlot = $padHaystackSlot;
                    $inlineArrayLiteralArgWired = true;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
                && null === $valueSlot
                && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
            ) {
                $leadingMergeFinalSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                if (null !== $leadingMergeFinalSlot) {
                    $valueSlot = $leadingMergeFinalSlot;
                    $inlineArrayLiteralArgWired = true;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgIsDeadInlineTemporary($sendProbe)
                && $this->callArgUsesHaystackFamilyArrayProducerResolution(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $sendProbe
                )
                && !(
                    0 === (int) $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && null !== $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                        $this->arrayMergeFamilyInlineProducersForCfgCall($block->orig->children, $cfgCallOp),
                        0
                    )
                )
            ) {
                $haystackSiblingEmit = [];
                $haystackExecSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $haystackSiblingEmit
                );
                if (null !== $haystackExecSlot) {
                    if (
                        0 === (int) $argIndex
                        && \in_array(
                            strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                            true
                        )
                        && $inlineArrayLiteralArgWired
                        && null !== $valueSlot
                    ) {
                        // array_merge(['a'=>1], array_keys(...)) — arg #0 stays on leading INIT_ARRAY (#13760, #16418).
                    } else {
                        $valueSlot = (string) $haystackExecSlot;
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $finalIssetEmptySlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $finalIssetEmptySlot) {
                    $valueSlot = (string) $finalIssetEmptySlot;
                } else {
                    $inlineLiteralDimSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $inlineLiteralDimSlot) {
                        $valueSlot = $inlineLiteralDimSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')
            ) {
                $nestedExecSlot = $this->wireSubstrNestedSprintfCallArgSlot($block, $cfgCallOp, (int) $argIndex, $calleeName)
                    ?? $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    ) ?? $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                if (null !== $nestedExecSlot) {
                    $valueSlot = $nestedExecSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? null)
            ) {
                $valueSlot = $this->compileOperand($cfgCallOp->args[(int) $argIndex], $block, true);
            } elseif (null !== $cfgCallOp && null !== $block->orig) {
                $unaryTailSlot = $this->slotForImmediateUnaryHoistedCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName
                );
                if (null !== $unaryTailSlot) {
                    $valueSlot = $unaryTailSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['is_array', 'count', 'array_keys'],
                    true
                )
            ) {
                $arrayBuiltinArg = $cfgCallOp->args[0] ?? $arg;
                if ($arrayBuiltinArg instanceof Operand && $this->callArgIsDeadInlineTemporary($arrayBuiltinArg)) {
                    $namedLocalSlot = $this->namedLocalCallArgSlotIfBound(
                        $arrayBuiltinArg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    ) ?? $this->slotForNamedLocalFromAssignVarOperand($arrayBuiltinArg, $block);
                    if (null !== $namedLocalSlot) {
                        $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, (int) $namedLocalSlot, true);
                    } else {
                        $nestedFileSlot = null;
                        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
                        if (\is_int($callIndex) && $callIndex > 0 && null !== $block->orig) {
                            $immediate = $block->orig->children[$callIndex - 1] ?? null;
                            if (
                                ($immediate instanceof Op\Expr\FuncCall || $immediate instanceof Op\Expr\NsFuncCall)
                                && $this->isNestedCallArgProducerForConsumer(
                                    $immediate,
                                    $cfgCallOp,
                                    $callIndex - 1,
                                    $callIndex,
                                    $block->orig->children
                                )
                            ) {
                                $nestedFileSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                                    $block,
                                    $callIndex - 1,
                                    $block->orig->children
                                );
                            }
                        }
                        $nestedFileSlot ??= $this->resolveAdjacentNestedFuncCallArgSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $nestedFileSlot) {
                            $valueSlot = (string) $nestedFileSlot;
                        }
                    }
                }
            }
            if (null !== $cfgCallOp && !$this->isEmbeddedCallLiteralArg($arg)) {
                $pendingDimFetchSlot = null;
                if (null !== $dimFetchSlot) {
                    // stream_set_blocking($pipes[1], false) — dim-fetch slot is arg #0 only (#18186).
                    $pendingDimFetchSlot = $this->lastPendingCallArgArrayDimFetchSlot($block, $sends);
                    if (null === $pendingDimFetchSlot) {
                        $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
                    }
                } elseif ($this->callArgIsDeadInlineHaystackFamilySlot(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $arg
                )) {
                    $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
                }
                if (null !== $pendingDimFetchSlot) {
                    $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                        $block,
                        $cfgCallOp,
                        false
                    );
                    // The pending scan returns the LAST dim-fetch read, which belongs to the trailing
                    // argument. Applying it to every index made t2($r['a'], $r['b']) send $r['b'] twice
                    // (#23354). Earlier arguments keep the per-index slot resolved above; the override
                    // still runs when nothing else produced one, so it stays a fallback.
                    if (
                        null === $immediatePropertySlot
                        && (
                            null === $valueSlot
                            || (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
                        )
                    ) {
                        $valueSlot = (string) $pendingDimFetchSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $comparisonSlot = $this->slotForComparisonPreludeDeadInlineCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $comparisonSlot) {
                    $valueSlot = $comparisonSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $mergeForcedSlot = $this->finalizeArrayMergeFamilyCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $mergeForcedSlot) {
                    $valueSlot = $mergeForcedSlot;
                }
            }
            if (null !== $cfgCallOp) {
                $leadingConstFuncPreludeEmit = [];
                $leadingConstFuncPreludeSlot = $this->finalizeLeadingConstFetchFuncCallPreludeCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $leadingConstFuncPreludeEmit
                );
                if ([] !== $leadingConstFuncPreludeEmit) {
                    $sends = array_merge($sends, $leadingConstFuncPreludeEmit);
                }
                if (null !== $leadingConstFuncPreludeSlot) {
                    $valueSlot = $leadingConstFuncPreludeSlot;
                }
            }
            if (null !== $cfgCallOp) {
                $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                    $block,
                    $cfgCallOp,
                    false
                );
                if (
                    null !== $immediatePropertySlot
                    && $this->callArgIsDeadInlineTemporary($callArgOperand ?? $arg)
                ) {
                    $valueSlot = $immediatePropertySlot;
                }
            }
            $syncedFinalArgSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgOperand ?? $arg);
            if (null !== $syncedFinalArgSlot) {
                $valueSlot = (string) $syncedFinalArgSlot;
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null)
                && null !== $block->orig
            ) {
                $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero(
                    $block,
                    $cfgCallOp,
                    $sends
                );
                if (null !== $hoistedScalarArgSlot) {
                    $valueSlot = $hoistedScalarArgSlot;
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $bitmaskArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                $bitmaskSlot = $this->tryResolveInlineBitmaskCallArgSlot(
                    $bitmaskArgProbe,
                    $block,
                    $sends,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null === $bitmaskSlot) {
                    $trailingBitmaskArgIndex = $this->trailingNonEmbeddedCallArgIndex($cfgCallOp);
                    $bitmaskCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (\is_int($bitmaskCallIndex) && $bitmaskCallIndex > 0) {
                        $bitmaskImmediate = $block->orig->children[$bitmaskCallIndex - 1] ?? null;
                        if ($bitmaskImmediate instanceof Op\Expr\Assign) {
                            $hoistedRhs = $bitmaskCallIndex > 1
                                ? ($block->orig->children[$bitmaskCallIndex - 2] ?? null)
                                : null;
                            if (
                                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
                            ) {
                                $bitmaskImmediate = $hoistedRhs;
                            } else {
                                $bitmaskImmediate = $bitmaskImmediate->expr;
                            }
                        }
                        if (
                            (int) $argIndex === $trailingBitmaskArgIndex
                            && (
                                $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseOr
                                || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseAnd
                                || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseXor
                            )
                            && (
                                $this->callArgIsDeadInlineTemporary($bitmaskArgProbe)
                                || $this->callArgIsAssignInCallOperand($bitmaskArgProbe)
                            )
                            && !$this->callArgOperandExpectsArrayProducer($bitmaskArgProbe)
                        ) {
                            $namedDest = $this->slotForHoistedAssignInCallNamedDest($block, $cfgCallOp);
                            if (null !== $namedDest) {
                                $bitmaskSlot = $namedDest;
                            } elseif (null === $block->slotForOperand($bitmaskImmediate->result)) {
                                foreach ($this->compileExpr($bitmaskImmediate, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                            } else {
                                $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                            }
                        }
                    }
                }
                if (
                    null !== $bitmaskSlot
                    && (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
                ) {
                    $valueSlot = (string) $bitmaskSlot;
                }
            }
            if (null !== $nullLiteralCallArgSlot) {
                $valueSlot = $nullLiteralCallArgSlot;
            }
            // Last word to the exact argument->producer link (#23354). Every heuristic above resolves
            // a hoisted argument from the statement before the call, which is only ever the TRAILING
            // argument's producer; php-cfg records the real producer as the argument temporary's sole
            // writer, so this is the one mapping that is right by construction rather than by shape.
            $exactSlot = $this->exactHoistedCallArgProducerSlot($block, $cfgCallOp, (int) $argIndex, $sends);
            if (null !== $exactSlot) {
                $valueSlot = $exactSlot;
            }
            // Bare named locals ($x as call arg): CV assign-dest must win over later heuristics
            // that re-bind the call-site clone Temporary to a fresh empty slot (#23893, re-#23354).
            $bareLocalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                $bareLocalProbe instanceof Operand
                && null !== Block::resolveVariableName($bareLocalProbe)
                && !$this->callArgIsDeadInlineTemporary($bareLocalProbe)
            ) {
                $bareNamedDest = $block->slotForNamedAssignDest($bareLocalProbe);
                if (null !== $bareNamedDest) {
                    $valueSlot = $this->resolveNamedAssignCallArgSlot(
                        $block,
                        (int) $bareNamedDest,
                        $calleeName,
                        (int) $argIndex,
                        $bareLocalProbe
                    );
                } elseif (null === $valueSlot) {
                    $valueSlot = $this->compileOperand($bareLocalProbe, $block, true);
                }
            }
            // [...new ArrayIterator([...])] as call arg: nested ctor Array_ slot may win over
            // the spread INIT_ARRAY that sits after FUNCCALL_EXEC_RETURN (#24645).
            $callArgProbeFinal = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                null !== $cfgCallOp
                && $callArgProbeFinal instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArgProbeFinal)
                && (
                    $this->callArgOperandExpectsArrayProducer($callArgProbeFinal)
                    || $this->callArgIsDeadUnknownOrMixedTemporary($callArgProbeFinal)
                )
            ) {
                $spreadResultSlot = $this->slotForArraySpreadResultAfterLastExecReturn($block, $sends);
                if (null !== $spreadResultSlot) {
                    $valueSlot = $spreadResultSlot;
                }
            }
            // array_merge([1], $x ? [2] : [3]) / twoway(FLAG, 'C' ?: 'D') — non-Phi sibling of
            // ?: must keep Array_/ConstFetch writer slot, not the merge phi (#25337).
            if (null !== $cfgCallOp && \is_array($cfgCallOp->args ?? null)) {
                $ternarySiblingProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($ternarySiblingProbe instanceof Operand) {
                    $ternarySiblingSlot = $this->resolveNonPhiSiblingOfTernaryCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $ternarySiblingProbe,
                        $sends
                    );
                    if (null !== $ternarySiblingSlot) {
                        $valueSlot = $ternarySiblingSlot;
                    }
                }
            }
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, $unpackFlag);
        }

        return $sends;
    }
}
