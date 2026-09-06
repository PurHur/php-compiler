<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ReferencableCheck;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * Hoisted scalar/concat/unary/bitmask inline call-arg fold helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see tryFoldHoistedBoolNullLiteralCallArg} and unary/bitmask/concat/
 * arithmetic resolvers. Compile-time value fold + proc_open/array_slice helpers
 * live in {@see CallArgCompileTimeValueAndProcOpenFold}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as AdjacentNestedCallArgSlots).
 */
trait InlineCallArgCompileTimeFold
{
    /**
     * php-cfg hoists `true`/`false`/`null` as a ConstFetch stmt before FuncCall with a dead arg temp (#9140, #9260).
     */
    private function tryFoldHoistedBoolNullLiteralCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp || !property_exists($cfgCallOp, 'args')) {
            return null;
        }
        if ($this->callArgIsErrorSuppressForwardedResult($arg, $block)) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        if (!is_array($callArgs) || [] === $callArgs) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        // var_export(f(), true) / var_export($o->m(), true) — arg #0 is hoisted producer, not ConstFetch true (#16556, #17251).
        if (0 === $argIndex) {
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $prev = $children[$i] ?? null;
                if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                if (
                    $prev instanceof Op\Expr\FuncCall
                    || $prev instanceof Op\Expr\NsFuncCall
                    || $prev instanceof Op\Expr\MethodCall
                    || $prev instanceof Op\Expr\StaticCall
                ) {
                    $consumerFn = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                    if ('var_export' === $consumerFn) {
                        if (
                            ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
                            && 'define' === strtolower($this->resolveCfgFuncCallName($prev) ?? '')
                        ) {
                            break;
                        }
                        $callArgZero = $callArgs[0] ?? null;
                        if (
                            $callArgZero instanceof Operand
                            && null !== $prev->result
                            && (
                                $callArgZero === $prev->result
                                || $this->operandsReferToSameVariable($callArgZero, $prev->result)
                            )
                        ) {
                            return null;
                        }
                    }
                    if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                        $fn = $this->resolveCfgFuncCallName($prev);
                        if (null !== $fn && ReferencableCheck::isArrayInternalPointerBuiltin($fn)) {
                            return null;
                        }
                    }
                }
                break;
            }
        }
        $trailingConstFetches = [];
        $skipNonBoolNullConstFetch = 'json_decode' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && \count($callArgs) >= 4;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    array_unshift($trailingConstFetches, $prev);
                    continue;
                }
                if ($skipNonBoolNullConstFetch) {
                    continue;
                }
                break;
            }
            if ($prev instanceof Op\Expr\ClassConstFetch) {
                if ($skipNonBoolNullConstFetch) {
                    continue;
                }
                break;
            }
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            // Hoisted null feeds Concat operands, not a trailing call arg (#10663, zend_operators.c).
            if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                return null;
            }
            if ($skipNonBoolNullConstFetch
                && ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
            ) {
                break;
            }
            break;
        }
        if ([] === $trailingConstFetches) {
            if (
                'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && 1 === $argIndex
                && $arg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($arg)
            ) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($children, $cfgCallOp);
                if ($this->producersIncludeInlineArrayUnionPlus($producers)) {
                    return $this->slotForBoolNullConstFetchBeforeLastFuncCallInit($block);
                }
            }

            return null;
        }
        if ('array_splice' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($children, $cfgCallOp);
            if (null !== $this->matchArraySpliceUnaryOffsetReplacementProducers(
                $producers,
                $argIndex,
                \count($callArgs),
                'array_splice'
            )) {
                return null;
            }
        }
        $mbFunc = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if (\in_array($mbFunc, ['mb_substr', 'mb_strcut'], true)) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($children, $cfgCallOp);
            if (null !== $this->matchMbstringUnaryOffsetNullLengthProducers(
                $producers,
                $argIndex,
                \count($callArgs),
                $mbFunc
            )) {
                return null;
            }
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        if (
            \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['in_array', 'array_search'], true)
            && 1 === $argIndex
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return null;
        }
        if (
            0 === $argIndex
            && null !== $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes($callIndex, $children)
        ) {
            // var_export($a[1][0], true) — arg #0 is chained dim-fetch, not trailing ConstFetch (#17894, re-#15945).
            return null;
        }
        foreach ($trailingConstFetches as $fetch) {
            if (
                null !== $fetch->result
                && $this->operandsReferToSameVariable($fetch->result, $callArg)
            ) {
                $slot = $this->slotForHoistedScalarConstFetchCallArg($fetch, $block);
                if (null !== $slot) {
                    return $slot;
                }
            }
        }
        if (\in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['in_array', 'array_search'], true)) {
            // Needle/strict slots must not use positional trailingConstFetches — null for [null] sits before Array_ (#16096).
            return null;
        }
        if (
            'array_chunk' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && 2 === $argIndex
            && 1 === \count($trailingConstFetches)
            && !$this->isEmbeddedCallLiteralArg($callArgs[1] ?? null)
        ) {
            $fetch = $trailingConstFetches[0];
            if ($fetch instanceof Op\Expr\ConstFetch) {
                return $this->slotForHoistedScalarConstFetchCallArg($fetch, $block);
            }
        }
        $nonEmbeddedArgIndices = [];
        foreach ($callArgs as $i => $candidate) {
            // Named CVs / Phi auto-captures must not consume trailing ConstFetch null/true/false
            // slots — `fn() => new C($x, null)` was binding ConstFetch onto `$x` (#31720).
            if (
                !$this->isEmbeddedCallLiteralArg($candidate)
                && $this->callArgIsDeadInlineTemporary($candidate)
            ) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $boolNullArgIndices = $nonEmbeddedArgIndices;
        if (
            $skipNonBoolNullConstFetch
            && isset($nonEmbeddedArgIndices[0])
            && 0 === $nonEmbeddedArgIndices[0]
            && null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes($cfgCallOp, $callIndex, $children)
        ) {
            $boolNullArgIndices = array_values(array_filter(
                $boolNullArgIndices,
                static fn (int $idx): bool => 0 !== $idx
            ));
        }
        if (
            null !== $this->nonConstInlineProducerBeforeTrailingScalarConstFetchPreludes($callIndex, $children)
            && \count($trailingConstFetches) < \count($nonEmbeddedArgIndices)
        ) {
            // importNode($doc->documentElement, true) — scalar ConstFetch maps to trailing args only (#16318).
            $boolNullArgIndices = \array_slice(
                $nonEmbeddedArgIndices,
                \count($nonEmbeddedArgIndices) - \count($trailingConstFetches)
            );
        }
        $producerOrdinal = array_search($argIndex, $boolNullArgIndices, true);
        if (false === $producerOrdinal) {
            return null;
        }
        // Only block folding onto arg #0 in an @ end-block (suppress inner result — #15916).
        // Trailing return-mode true after a sibling PropertyFetch keeps producerOrdinal 0 with
        // boolNullArgIndices=[1]; rejecting that misbinds PropertyFetch to arg1 (#21975).
        if (
            0 === $argIndex
            && null !== $block->orig
            && $this->isErrorSuppressEndBlock($block->orig)
        ) {
            return null;
        }
        if (
            0 === $producerOrdinal
            && \count($trailingConstFetches) < \count($nonEmbeddedArgIndices)
            && null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                $cfgCallOp,
                $callIndex,
                $children
            )
            && !$skipNonBoolNullConstFetch
        ) {
            // var_export(array_keys($a, null), true) — sole trailing ConstFetch is arg #1 (#11272, #16298).
            // json_decode(g(), true, N, JSON_*) — boolNullArgIndices already excludes arg #0 (#16319).
            return null;
        }
        $fetch = $trailingConstFetches[$producerOrdinal] ?? null;
        if (!$fetch instanceof Op\Expr\ConstFetch) {
            return null;
        }

        return $this->slotForHoistedScalarConstFetchCallArg($fetch, $block);
    }

    /**
     * var_dump(...); ini_get_all(null, false) — trailing ConstFetch args, not sibling FuncCall returns (#15931, #16065).
     */
    private function callHasOnlyTrailingHoistedScalarConstFetchArgs(Op $cfgCallOp, Block $block): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        $expected = 0;
        $matched = 0;
        foreach ($cfgCallOp->args as $i => $callArg) {
            if (null === $callArg || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($callArg)) {
                return false;
            }
            ++$expected;
            if (null !== $this->tryFoldHoistedBoolNullLiteralCallArg($callArg, $block, $cfgCallOp, (int) $i)) {
                ++$matched;
            }
        }

        return $expected > 0 && $matched === $expected;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function consumerHasTrailingHoistedScalarConstFetchArgPreludes(
        Op $consumer,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (!property_exists($consumer, 'args') || !is_array($consumer->args)) {
            return false;
        }
        $scalarPreludes = [];
        for ($i = $consumerIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if ($child instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($child->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    array_unshift($scalarPreludes, $child);
                    continue;
                }
                break;
            }
            if (
                $child instanceof Op\Expr\FuncCall
                || $child instanceof Op\Expr\NsFuncCall
                || $child instanceof Op\Expr\StaticCall
                || $child instanceof Op\Expr\MethodCall
            ) {
                break;
            }
            if ($child instanceof Op\Expr\Assign) {
                continue;
            }
            break;
        }
        if ([] === $scalarPreludes) {
            return false;
        }
        $hoistedArgCount = 0;
        foreach ($consumer->args as $callArg) {
            if (
                null !== $callArg
                && !$this->isEmbeddedCallLiteralArg($callArg)
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                ++$hoistedArgCount;
            }
        }

        return $hoistedArgCount > 0 && \count($scalarPreludes) === $hoistedArgCount;
    }

    private function slotForHoistedScalarConstFetchCallArg(Op\Expr\ConstFetch $fetch, Block $block): ?int
    {
        $slot = $block->slotForOperand($fetch->result);
        if (null === $slot) {
            $vm = $this->tryFoldGlobalConstFetch($fetch);
            if (null !== $vm) {
                return $block->registerConstant($fetch->result ?? new Operand\Temporary(), $vm);
            }
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($fetch->result);
        }
        if (null !== $slot) {
            return $slot;
        }

        return null;
    }

    /**
     * Hoisted call-arg ConstFetch may compile before FUNCCALL_INIT; php-src resolves callee first (#17697).
     */
    private function prependFuncCallInitBeforeTrailingArgConstFetches(Block $block, OpCode $init): bool
    {
        if ($this->inlineNestedProducerOpsInArgSends) {
            return false;
        }
        $n = \count($block->opCodes);
        if (0 === $n || OpCode::TYPE_CONST_FETCH !== $block->opCodes[$n - 1]->type) {
            return false;
        }
        $insertAt = $n;
        while ($insertAt > 0 && OpCode::TYPE_CONST_FETCH === $block->opCodes[$insertAt - 1]->type) {
            --$insertAt;
        }
        // (CONST)(...) — ConstFetch writes the dynamic callee slot; keep it before INIT (#26240).
        if (null !== $init->arg1) {
            for ($i = $insertAt; $i < $n; ++$i) {
                if ($block->opCodes[$i]->arg1 === $init->arg1) {
                    return false;
                }
            }
        }
        array_splice($block->opCodes, $insertAt, 0, [$init]);
        $block->nOpCodes = \count($block->opCodes);
        $block->invalidateOpcodeDerivedIndexes();

        return true;
    }

    /**
     * var_export([...] + [...], true) — true is emitted as TYPE_CONST_FETCH before FUNCCALL_INIT (#11511).
     */
    private function slotForBoolNullConstFetchBeforeLastFuncCallInit(Block $block): ?int
    {
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_INIT !== $block->opCodes[$i]->type) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; --$j) {
                $prev = $block->opCodes[$j];
                if (OpCode::TYPE_CONST_FETCH === $prev->type && null !== $prev->arg1) {
                    return (int) $prev->arg1;
                }

                break;
            }

            break;
        }

        return null;
    }

    /**
     * Fold or lower UnaryMinus/UnaryPlus call args before ARG_SEND (#13387, zend_operators.c concat chains).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveUnaryLiteralCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && $this->unaryLiteralFeedsSiblingArrayDimFetchDim($cfgCallOp, $block)
        ) {
            return null;
        }
        $unaryRoot = $this->unwrapOperandChain($arg);
        if (!$unaryRoot instanceof Op\Expr\UnaryMinus && !$unaryRoot instanceof Op\Expr\UnaryPlus) {
            $unaryRoot = $this->unaryLiteralProducerForHoistedCallArg($cfgCallOp, $argIndex, $block, $arg);
        }
        if (!$unaryRoot instanceof Op\Expr\UnaryMinus && !$unaryRoot instanceof Op\Expr\UnaryPlus) {
            return null;
        }
        $vm = $this->tryFoldUnaryLiteralDefault($unaryRoot);
        if (null !== $vm) {
            return $block->registerConstant($unaryRoot->result, $vm);
        }
        if (null === $block->slotForOperand($unaryRoot->result)) {
            foreach ($this->compileExpr($unaryRoot, $block) as $op) {
                $emitOps[] = $op;
            }
        }

        return $block->slotForOperand($unaryRoot->result);
    }

    /**
     * Trailing scalar/flag option prelude for multi-arg call/ctor (#18523, #19735, #19738).
     * file_put_contents(..., FILE_APPEND|LOCK_EX), new C(new X, new Y, 1+2), new C(..., -1), (int) casts.
     * Dead arg temp must use the prelude result, not prior New_/call return; only the trailing arg binds.
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveInlineBitmaskCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $assignProducer = null;
        $last = $producers[\count($producers) - 1] ?? null;
        if ($last instanceof Op\Expr\Assign) {
            $assignProducer = $last;
            $last = $last->expr;
        }
        // ConstFetch/ClassConstFetch options use dedicated remaps; bind arithmetic/bitwise/unary/cast here.
        $isScalarOptionPrelude = $this->isArithmeticInlineCallArgProducer($last)
            || $last instanceof Op\Expr\UnaryMinus
            || $last instanceof Op\Expr\UnaryPlus
            || $last instanceof Op\Expr\BitwiseNot
            || $last instanceof Op\Expr\Cast;
        if (!$isScalarOptionPrelude) {
            return null;
        }
        if ($argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($this->compileExpr($last, $block) as $op) {
                $emitOps[] = $op;
            }
        }

        return $block->slotForOperand($last->result);
    }

    /** Last call arg index that is not an embedded literal (e.g. json_encode($v, JSON_* | JSON_*)). */
    private function trailingNonEmbeddedCallArgIndex(Op $cfgCallOp): int
    {
        if (!\is_array($cfgCallOp->args ?? null)) {
            return -1;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidateArg) {
            if (null !== $candidateArg && !$this->isEmbeddedCallLiteralArg($candidateArg)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }

        return $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? -1;
    }

    /** file_put_contents($f, 'a', FILE_APPEND | LOCK_EX) — skip adjacent FuncCall rewire (#18523). */
    private function immediatePredecessorIsInlineBitmaskProducer(Op $cfgCallOp, Block $block): bool
    {
        if (null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;

        return $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor;
    }

    /**
     * Lower encapsed ConcatList call args when php-cfg allocates a dead arg temp (#13466).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveEncapsedConcatListCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        $concat = $this->concatListProducerForHoistedCallArg($cfgCallOp, $argIndex, $block, $arg);
        if (!$concat instanceof Op\Expr\ConcatList) {
            return null;
        }
        if (null === $block->slotForOperand($concat->result)) {
            $this->compileOp($concat, $block);
        }

        return $block->slotForOperand($concat->result);
    }

    /**
     * Lower chained BinaryOp\Concat call args when php-cfg allocates a dead arg temp (#13458, #13572).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveChainedConcatCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $chain = $this->chainedConcatInlineCallArgProducersBeforeCall(
            $block->orig->children,
            $callIndex,
            $cfgCallOp
        );
        if (null === $chain) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($cfgCallOp->args);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $last = $chain[\count($chain) - 1];
        if (null === $last->result) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($chain as $concat) {
                foreach ($this->compileExpr($concat, $block) as $op) {
                    $emitOps[] = $op;
                }
            }
        }

        return $block->slotForOperand($last->result);
    }

    /**
     * Lower chained Mul/Div/Plus/Minus call args when php-cfg allocates a dead arg temp (#15929).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveChainedArithmeticCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $chain = $this->chainedArithmeticInlineCallArgProducersBeforeCall(
            $block->orig->children,
            $callIndex,
            $cfgCallOp
        );
        if (null === $chain) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($cfgCallOp->args);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $last = $chain[\count($chain) - 1];
        if (null === $last->result) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($chain as $arithmetic) {
                foreach ($this->compileExpr($arithmetic, $block) as $op) {
                    $emitOps[] = $op;
                }
            }
        }

        return $block->slotForOperand($last->result);
    }

    /**
     * php-cfg hoists encapsed ConcatList before FuncCall with a distinct dead arg temp (#13466).
     */
    private function concatListProducerForHoistedCallArg(
        ?Op $callOp,
        int $argIndex,
        Block $block,
        Operand $arg
    ): ?Op\Expr\ConcatList {
        if (null === $callOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $callArg = \is_array($callOp->args ?? null) ? ($callOp->args[$argIndex] ?? $arg) : $arg;
        // php-cfg links dead New_/call arg temps via $arg->ops even when ClassConstFetch sits
        // between ConcatList and the call (`new T("x$v", C::K)`, #22971 / #13466).
        $writer = $this->soleWriteExprForOperand($callArg);
        if ($writer instanceof Op\Expr\ConcatList) {
            return $writer;
        }
        foreach ($callArg->ops ?? [] as $op) {
            if ($op instanceof Op\Expr\ConcatList) {
                return $op;
            }
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Assign) {
                if ($this->operandsReferToSameVariable($child->var, $callArg)) {
                    $assignExpr = $child->expr;
                    while ($assignExpr instanceof Operand\Temporary && null !== $assignExpr->original) {
                        $assignExpr = $assignExpr->original;
                    }
                    if ($assignExpr instanceof Op\Expr\ConcatList) {
                        return $assignExpr;
                    }
                    if ($i > 0) {
                        $prior = $block->orig->children[$i - 1];
                        if (
                            $prior instanceof Op\Expr\ConcatList
                            && null !== $prior->result
                            && (
                                $this->operandsReferToSameVariable($prior->result, $child->expr)
                                || $this->operandsReferToSameVariable($prior->result, $assignExpr)
                            )
                        ) {
                            return $prior;
                        }
                    }
                }

                break;
            }
            if ($child instanceof Op\Expr\ConcatList) {
                if (null !== $child->result) {
                    if ($this->operandsReferToSameVariable($child->result, $callArg)) {
                        return $child;
                    }
                    // php-cfg dead-temp alias: hoisted call arg temp may differ from ConcatList.result (#13466).
                    if ($i === $callIndex - 1 && $callArg instanceof Operand\Temporary) {
                        return $child;
                    }
                }

                return null;
            }
            // Sibling ClassConstFetch / ConstFetch / UnaryMinus between ConcatList and New_ (#22971).
            if (
                $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\UnaryMinus
                || $child instanceof Op\Expr\UnaryPlus
                || $child instanceof Op\Expr\PropertyFetch
                || $child instanceof Op\Expr\ArrayDimFetch
                || $child instanceof Op\Expr\BinaryOp\Concat
            ) {
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * php-cfg echo ConcatList hoists sibling FuncCalls with Concat stmts between them (#13387).
     */
    private function unaryLiteralFeedsSiblingArrayDimFetchDim(?Op $callOp, Block $block): bool
    {
        if (null === $callOp || null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if (!$child instanceof Op\Expr\UnaryMinus && !$child instanceof Op\Expr\UnaryPlus) {
                return false;
            }
            $next = $block->orig->children[$i + 1] ?? null;

            return $next instanceof Op\Expr\ArrayDimFetch
                && null !== $child->result
                && null !== $next->dim
                && (
                    $next->dim === $child->result
                    || $this->operandsReferToSameVariable($next->dim, $child->result)
                );
        }

        return false;
    }

    private function unaryLiteralProducerForHoistedCallArg(
        ?Op $callOp,
        int $argIndex,
        Block $block,
        Operand $arg
    ): Op\Expr\UnaryMinus|Op\Expr\UnaryPlus|null {
        if (null === $callOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $callArg = \is_array($callOp->args ?? null) ? ($callOp->args[$argIndex] ?? $arg) : $arg;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\UnaryMinus || $child instanceof Op\Expr\UnaryPlus) {
                if (null !== $child->result) {
                    if ($this->operandsReferToSameVariable($child->result, $callArg)) {
                        return $child;
                    }
                    // php-cfg dead-temp alias: hoisted call arg temp may differ from UnaryMinus.result (#13387, #13434).
                    // Trailing arg with immediate UnaryMinus/Plus (ceil(-2.5) after concat chain, ftruncate($f, -1)).
                    // ftruncate(fopen(), -1) arg #0 must not take callIndex-1 UnaryMinus — only trailing args (#12622).
                    if (
                        $i === $callIndex - 1
                        && $callArg instanceof Operand\Temporary
                        && is_array($callOp->args ?? null)
                        && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? $arg)
                    ) {
                        $argCount = \count($callOp->args);
                        if ($argIndex === $argCount - 1) {
                            return $child;
                        }
                        $producerSlot = $this->inlineHoistedProducerSlotIndexForCallArg(
                            $callOp->args,
                            $argIndex,
                            $block,
                            $callOp
                        );
                        if (null !== $producerSlot) {
                            // Single hoisted dead-temp unary arg (#13508): producer walk skips immediate
                            // UnaryMinus when the consumer FuncCall is the next sibling (same as trailing
                            // wiring for ceil(-2.5) / ftruncate($f, -1) in #13434).
                            if (0 === $producerSlot && 0 === $argIndex) {
                                $deadHoisted = 0;
                                foreach ($callOp->args as $hoistedArg) {
                                    if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                                        ++$deadHoisted;
                                    }
                                }
                                if (1 === $deadHoisted) {
                                    return $child;
                                }
                            }
                            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $callOp
                            );
                            if (($producers[$producerSlot] ?? null) === $child) {
                                return $child;
                            }
                        }
                    }
                }

                return null;
            }
            if ($child instanceof Op\Expr\BinaryOp\Concat) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
        }

        return null;
    }
}
