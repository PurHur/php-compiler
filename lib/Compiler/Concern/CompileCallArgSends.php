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
 * post-NestedAdjacent final literal / named-local / haystack / dim / exact
 * producer slots in
 * {@see CallArgPostNestedFinalLiteralNamedLocalHaystackDimExactValueSlots};
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
            $this->resolveCallArgPostNestedFinalLiteralNamedLocalHaystackDimExactValueSlots(
                $arg,
                (int) $argIndex,
                $block,
                $calleeName,
                $cfgCallOp,
                $callArgOperand ?? $arg,
                $dimFetchSlot,
                $nullLiteralCallArgSlot,
                $sends,
                $valueSlot,
                $inlineArrayLiteralArgWired
            );
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, $unpackFlag);
        }

        return $sends;
    }
}
