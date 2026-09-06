<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ReferencableCheck;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * var_export nested inline + array_merge-family call-arg slots (#36387 / prior #36147).
 *
 * Extracted from {@see SiblingInlineCallArgProducerSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see slotForVarExportNestedInlineCallArg} through
 * {@see matchArrayMergeFamilyInlineCallArgProducer}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait VarExportNestedAndArrayMergeFamilyCallArgSlots
{
    /**
     * var_export(C::__set_state([]), true) — compile nested StaticCall/MethodCall before ARG_SEND (#11896).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForVarExportNestedInlineCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$emitOps = []
    ): ?int {
        if (0 !== $argIndex || 'var_export' !== $this->resolveCfgFuncCallName($cfgCallOp)) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand) {
            return null;
        }
        $stmtBefore = $block->orig->children[$callIndex - 1] ?? null;
        if (
            $stmtBefore instanceof Op\Expr\BinaryOp\Plus
            && 0 === $argIndex
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $plusSlot = $block->slotForOperand($stmtBefore->result);
            if (null === $plusSlot) {
                foreach ($this->compileExpr($stmtBefore, $block) as $op) {
                    $emitOps[] = $op;
                }
                $plusSlot = $block->slotForOperand($stmtBefore->result);
            }

            return null !== $plusSlot ? (int) $plusSlot : null;
        }
        if (
            $stmtBefore instanceof Op\Expr\Array_
            && $this->callArgIsDeadInlineTemporary($callArg)
            && (
                $this->callArgOperandExpectsArrayProducer($callArg)
                || $this->callArgIsDeadUnknownOrMixedTemporary($callArg)
            )
        ) {
            // Prefer INIT_ARRAY after last FUNCCALL_EXEC_RETURN so nested ctor Array_
            // (`new ArrayIterator([1,2,3])` inside `[...$it]`) is not re-sent (#24645).
            $arraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block)
                ?? $block->slotForOperand($stmtBefore->result);
            if (null === $arraySlot) {
                foreach ($this->compileArrayLiteral($stmtBefore, $block) as $op) {
                    $emitOps[] = $op;
                }
                $arraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block)
                    ?? $block->slotForOperand($stmtBefore->result)
                    ?? $this->slotForRecentInitArrayCallArg($block);
            }

            return null !== $arraySlot ? (int) $arraySlot : null;
        }
        if (
            ($stmtBefore instanceof Op\Expr\ConstFetch || $stmtBefore instanceof Op\Expr\ClassConstFetch)
            && 0 === $argIndex
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            // var_export($expr, true|false) — hoisted return flag is not arg #0 (#26702, #17895).
            $skipReturnFlagConst = false;
            if (
                $stmtBefore instanceof Op\Expr\ConstFetch
                && \is_array($cfgCallOp->args ?? null)
                && \count($cfgCallOp->args) >= 2
            ) {
                $flagName = strtolower($this->staticNameFromOperand($stmtBefore->name) ?? '');
                if (\in_array($flagName, ['true', 'false'], true)) {
                    $skipReturnFlagConst = true;
                }
            }
            if (!$skipReturnFlagConst) {
                // define('ARR', …); var_export(ARR) — ConstFetch prelude, not define() bool return (#17872).
                $constSlot = $block->slotForOperand($stmtBefore->result);
                if (null === $constSlot) {
                    foreach ($this->compileExpr($stmtBefore, $block) as $op) {
                        $emitOps[] = $op;
                    }
                    $constSlot = $block->slotForOperand($stmtBefore->result);
                }

                return null !== $constSlot ? (int) $constSlot : null;
            }
        }
        $candidate = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\Array_) {
                // var_export([strlen('x'), …]) — stmt-before Array_ feeds arg #0, not hoisted element call (#15783, #10733, #16067).
                // Also accept unknown/mixed dead temps for spread arrays (#24645).
                if (
                    $i === $callIndex - 1
                    && (
                        $this->callArgOperandExpectsArrayProducer($callArg)
                        || $this->callArgIsDeadUnknownOrMixedTemporary($callArg)
                    )
                    && (
                        (null !== $child->result && $this->operandsReferToSameVariable($child->result, $callArg))
                        || $this->callArgIsDeadInlineTemporary($callArg)
                    )
                ) {
                    $candidate = $child;
                    break;
                }
                continue;
            }
            if ($this->isSiblingInlineCallProducerExpr($child)) {
                $stmtBefore = $block->orig->children[$callIndex - 1] ?? null;
                if (
                    $stmtBefore instanceof Op\Expr\Array_
                    && $this->callArgIsDeadInlineTemporary($callArg)
                    && (
                        $this->callArgOperandExpectsArrayProducer($callArg)
                        || $this->callArgIsDeadUnknownOrMixedTemporary($callArg)
                    )
                ) {
                    $candidate = $stmtBefore;
                    break;
                }
                $candidate = $child;
                break;
            }
            // var_export(require_once $f[, true]) — Include_/Eval_ feeds arg #0 (#25852, #21938).
            if (
                ($child instanceof Op\Expr\Include_ || $child instanceof Op\Expr\Eval_)
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                $candidate = $child;
                break;
            }
            // var_export($text->data) / var_export(!$o, true) — expression prelude after skipped true/false (#17540, #26702).
            if (
                $this->callArgIsDeadInlineTemporary($callArg)
                && $this->isImmediateVarExportExpressionPrelude($child)
            ) {
                $candidate = $child;
                break;
            }
            break;
        }
        if (!$candidate instanceof Op\Expr || null === $candidate->result) {
            return null;
        }
        $feedsCallArg = $this->inlineCallArgProducerFeedsCallArgOp($candidate, $cfgCallOp, $callArg)
            || (
                $this->callArgIsDeadInlineTemporary($callArg)
                && (
                    (
                        $candidate instanceof Op\Expr\Array_
                        && $this->callArgOperandExpectsArrayProducer($callArg)
                    )
                    || $candidate instanceof Op\Expr\StaticCall
                    || $candidate instanceof Op\Expr\FuncCall
                    || $candidate instanceof Op\Expr\NsFuncCall
                    || $candidate instanceof Op\Expr\MethodCall
                    || $candidate instanceof Op\Expr\Include_
                    || $candidate instanceof Op\Expr\Eval_
                    || $this->isImmediateVarExportExpressionPrelude($candidate)
                )
            );
        if (!$feedsCallArg) {
            return null;
        }
        if ($candidate instanceof Op\Expr\Array_) {
            $arraySlot = $block->slotForOperand($candidate->result);
            if (null === $arraySlot) {
                foreach ($this->compileArrayLiteral($candidate, $block) as $op) {
                    $emitOps[] = $op;
                }
                $arraySlot = $block->slotForOperand($candidate->result)
                    ?? $this->slotForRecentInitArrayCallArg($block);
            }

            return null !== $arraySlot ? (int) $arraySlot : null;
        }
        if (null === $block->slotForOperand($candidate->result)) {
            $prevForce = $this->forceDeferredSiblingCallReturnSlot;
            $this->forceDeferredSiblingCallReturnSlot = true;
            try {
                foreach ($this->compileExpr($candidate, $block) as $op) {
                    $emitOps[] = $op;
                }
            } finally {
                $this->forceDeferredSiblingCallReturnSlot = $prevForce;
            }
        }

        return $block->slotForOperand($candidate->result);
    }

    /**
     * Hoisted current()/key()/… before a consumer — wire arg to FuncCall result, not ephemeral Array_ (#10654).
     */
    private function slotForHoistedArrayPointerBuiltinCallArg(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        Operand $arg
    ): ?string {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        foreach ($producers as $producer) {
            if (!($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)) {
                continue;
            }
            $fn = $this->resolveCfgFuncCallName($producer);
            if (null === $fn || !ReferencableCheck::allowsEphemeralArrayLiteralByRef($fn)) {
                continue;
            }
            if (null === $producer->result || !$this->operandsReferToSameVariable($producer->result, $callArg)) {
                continue;
            }
            $slot = $block->slotForOperand($producer->result);
            if (null === $slot) {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $block->addOpCode($op);
                }
                $slot = $block->slotForOperand($producer->result);
            }

            return null !== $slot ? (string) $slot : null;
        }

        return null;
    }

    /**
     * strtotime('next Monday', strtotime('...')) — adjacent nested FuncCall feeds trailing arg (#10838).
     */
    /**
     * array_merge(array_keys($src), ['b']) — trailing inline Array_ must not reuse nested FuncCall slot (#13704).
     *
     * @param list<OpCode> $pendingSends
     */
    private function resolveArrayMergeTrailingInlineArrayCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        Operand $arg,
        array &$pendingSends
    ): ?string {
        if (1 !== $argIndex || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        // array_merge(['a'=>1], array_keys(...)) — arg #1 is sibling FuncCall, not any Array_ (#13775).
        $mergePair = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
        if ($mergePair instanceof Op\Expr\FuncCall || $mergePair instanceof Op\Expr\NsFuncCall) {
            return null;
        }
        foreach ($producers as $producer) {
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && null !== $producer->result
                && (
                    $producer->result === $callArg
                    || $this->operandsReferToSameVariable($producer->result, $callArg)
                )
            ) {
                // array_merge([...], array_keys(...)) — arg #1 is nested FuncCall, not trailing Array_ (#13760).
                return null;
            }
        }
        $hasNestedFuncCall = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $hasNestedFuncCall = true;
                break;
            }
        }
        // array_merge([1], [2]) — sibling flat Array_ literals use normal producer matching (#10093).
        if (!$hasNestedFuncCall) {
            return null;
        }
        $mergeMapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
        if ($mergeMapped instanceof Op\Expr\FuncCall || $mergeMapped instanceof Op\Expr\NsFuncCall) {
            // array_merge(['a'=>1], array_keys(...)) — arg #1 is nested FuncCall (#13760, #13775).
            return null;
        }
        $trailingArray = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $trailingArray = $producer;
            }
        }
        if (!$trailingArray instanceof Op\Expr\Array_) {
            $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
            if ($this->isEmbeddedCallLiteralArg($callArg)) {
                return (string) $this->compileOperand($callArg, $block, true);
            }

            return null;
        }
        if (null === $block->slotForOperand($trailingArray->result)) {
            foreach ($this->compileExpr($trailingArray, $block) as $op) {
                $pendingSends[] = $op;
            }
        }
        $slot = $block->slotForOperand($trailingArray->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * array_combine() with sibling inline producers — FuncCall+Array_ or Array_+Array_ (#13776, #10214).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayCombineInlineProducers(array $producers, int $argIndex): ?Op\Expr
    {
        if ($argIndex < 0 || $argIndex > 1) {
            return null;
        }
        $arrayProducers = [];
        $funcProducer = null;
        $funcPos = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $funcProducer = $producer;
                $funcPos = $pi;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $arrayProducers[] = $producer;
            }
        }
        if (2 === \count($producers) && 2 === \count($arrayProducers) && null === $funcProducer) {
            return $arrayProducers[$argIndex];
        }
        if (2 === \count($producers) && null !== $funcProducer && 1 === \count($arrayProducers)) {
            return 0 === $argIndex ? $funcProducer : $arrayProducers[0];
        }
        // array_combine(array_keys(['a'=>1,'b'=>2]), [10,20]) — inner Array_ + FuncCall + trailing Array_ (#15558, #13776).
        // array_combine($k, [10,20]) after $k=array_keys(...) — trailing values Array_, not haystack (#16295).
        if (null !== $funcProducer && null !== $funcPos && \count($producers) >= 3) {
            if (0 === $argIndex) {
                return $funcProducer;
            }
            $arrayProducers = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if ([] !== $arrayProducers) {
                return $arrayProducers[\count($arrayProducers) - 1];
            }

            return null;
        }

        return null;
    }

    /**
     * array_combine($k, […]) — sibling array_keys EXEC_RETURN must not steal inline values arg (#16295).
     */
    private function arrayCombineSkipsSiblingFuncExecArgSlot(
        Op $cfgCallOp,
        int $argIndex,
        ?Block $block = null
    ): bool {
        if (
            'array_combine' !== $this->resolveCfgFuncCallName($cfgCallOp)
            || 1 !== $argIndex
            || !property_exists($cfgCallOp, 'args')
            || !\is_array($cfgCallOp->args)
        ) {
            return false;
        }
        $valuesArg = $cfgCallOp->args[1] ?? null;
        $keysArg = $cfgCallOp->args[0] ?? null;

        return !$this->callArgIsDeadInlineTemporary($keysArg)
            && $this->callArgIsDeadInlineTemporary($valuesArg)
            && $this->callArgOperandExpectsArrayProducer($valuesArg);
    }

    /**
     * array_merge() with one hoisted FuncCall and one sibling Array_ producer (#12450, #13704, #13760).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayMergeFuncCallAndArrayInlineProducers(array $producers, int $argIndex): ?Op\Expr
    {
        if (2 !== \count($producers)) {
            return null;
        }
        $funcIdx = null;
        $arrayIdx = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $funcIdx = $pi;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $arrayIdx = $pi;
            }
        }
        if (null === $funcIdx || null === $arrayIdx || $funcIdx === $arrayIdx) {
            return null;
        }
        if ($arrayIdx < $funcIdx) {
            return 0 === $argIndex ? $producers[$arrayIdx] : $producers[$funcIdx];
        }

        return 0 === $argIndex ? $producers[$funcIdx] : $producers[$arrayIdx];
    }

    /**
     * array_merge(['a'=>1], array_keys(['b'=>2])) — producer scan stops at nested array_keys
     * and omits the leading base Array_; prepend it before the nested FuncCall chain (#13759).
     *
     * @param list<Op\Expr> $producers
     * @return list<Op\Expr>
     */
    private function augmentArrayMergeFamilyInlineProducers(array $cfgChildren, Op $callOp, array $producers): array
    {
        if ([] === $producers) {
            return $producers;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex) {
            return $producers;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && \in_array($child, $producers, true)
            ) {
                $innerArray = $cfgChildren[$i - 1] ?? null;
                if (
                    $innerArray instanceof Op\Expr\Array_
                    && $this->isInlineExprCallArgProducer($innerArray)
                ) {
                    for ($j = $i - 2; $j >= 0; --$j) {
                        $prev = $cfgChildren[$j];
                        if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                            break;
                        }
                        if ($prev instanceof Op\Expr\Array_) {
                            if (!\in_array($prev, $producers, true)) {
                                array_unshift($producers, $prev);
                            }
                            break 2;
                        }
                    }
                }
                break;
            }
        }

        return $producers;
    }

    /**
     * @param list<Op\Expr> $producers
     * @return list<Op\Expr>
     */
    private function arrayMergeFamilyInlineProducersForCfgCall(array $cfgChildren, Op $callOp): array
    {
        return $this->augmentArrayMergeFamilyInlineProducers(
            $cfgChildren,
            $callOp,
            $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp)
        );
    }

    /**
     * Map consecutive hoisted Array_ producers to array-shaped call args by order (#10094, #10808).
     *
     * When every call arg is a dead temp and Array_ producer count matches arity, also treat
     * unknown/mixed dead temps as array args — ClassConstFetch inside `[A::class, 'm']` leaves
     * the callable temp untyped so type-only matching bound `$args` to the first Array_ (#27139).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchInlineArrayProducersToArrayCallArgs(
        array $producers,
        array $callArgs,
        int $argIndex
    ): ?Op\Expr {
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if ([] === $arrayProducers) {
            return null;
        }
        $arrayArgIndices = [];
        foreach ($callArgs as $i => $arg) {
            if (null !== $arg && $this->callArgOperandExpectsArrayProducer($arg)) {
                $arrayArgIndices[] = $i;
            }
        }
        // Sibling flat Array_ literals with equal arity — include unknown dead temps (#27139, #12730).
        if (
            \count($arrayProducers) >= 2
            && \count($arrayProducers) === \count($callArgs)
            && \count($arrayArgIndices) < \count($arrayProducers)
            && !$this->arrayProducersFormNestedChain($arrayProducers)
        ) {
            $allDeadTemps = true;
            foreach ($callArgs as $arg) {
                if (null === $arg || !$this->callArgIsDeadInlineTemporary($arg)) {
                    $allDeadTemps = false;
                    break;
                }
            }
            if ($allDeadTemps) {
                $arrayArgIndices = [];
                foreach ($callArgs as $i => $arg) {
                    if (
                        null !== $arg
                        && (
                            $this->callArgOperandExpectsArrayProducer($arg)
                            || $this->callArgIsDeadUnknownOrMixedTemporary($arg)
                        )
                    ) {
                        $arrayArgIndices[] = $i;
                    }
                }
            }
        }
        $position = array_search($argIndex, $arrayArgIndices, true);
        if (false === $position || !isset($arrayProducers[$position])) {
            return null;
        }
        if (1 === \count($arrayArgIndices) && \count($arrayProducers) >= 2) {
            $outer = $arrayProducers[\count($arrayProducers) - 1];
            foreach (\array_slice($arrayProducers, 0, -1) as $inner) {
                foreach ($outer->values as $value) {
                    if (null !== $value && $this->operandsReferToSameVariable($value, $inner->result)) {
                        return $outer;
                    }
                }
            }
            // id(['b']) inside array_merge(['a'], id(['b'])) — preceding producers include the
            // outer call's Array_; sole array arg binds to the nearest producer (#28891).
            return $outer;
        }

        return $arrayProducers[$position];
    }

    /**
     * array_merge* inline hoisted Array_ roots — flat siblings, nested chains, folded first arg (#10230, #15979).
     *
     * @param list<Op\Expr> $mergeProducers
     * @param list<Operand> $callArgs
     */
    private function matchArrayMergeFamilyFullInlineCallArgProducer(
        array $mergeProducers,
        int $argIndex,
        int $mergeArgCount,
        array $callArgs = []
    ): ?Op\Expr {
        $leadingNested = $this->matchLeadingNestedInlineArrayMergeFamilyCallArgProducer(
            $mergeProducers,
            $argIndex,
            $mergeArgCount
        );
        if (null !== $leadingNested) {
            return $leadingNested;
        }
        $mapped = $this->matchArrayMergeFamilyInlineCallArgProducer($mergeProducers, $argIndex);
        if (null === $mapped) {
            $mapped = $this->matchSiblingNestedArrayLiteralCallArgProducer(
                $mergeProducers,
                $argIndex,
                $mergeArgCount
            );
        }
        if (null === $mapped && [] !== $callArgs) {
            $mapped = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
                $mergeProducers,
                $argIndex,
                $mergeArgCount,
                $callArgs
            );
        }

        return $mapped;
    }

    /**
     * array_merge(array_keys($src), ['b']) — nested FuncCall + trailing Array_, optional stmt Array_ (#15551).
     * array_merge_recursive(['a'=>1], ['a'=>2]) — sibling flat Array_ literals (#15552).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayMergeFamilyInlineCallArgProducer(array $producers, int $argIndex): ?Op\Expr
    {
        if (2 === \count($producers)) {
            $pair = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
            if (null !== $pair) {
                return $pair;
            }
        }
        $funcProducer = null;
        $funcPos = null;
        $arrayProducers = [];
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $funcProducer = $producer;
                $funcPos = $pi;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $arrayProducers[] = $producer;
            }
        }
        if (null !== $funcProducer && null !== $funcPos && [] !== $arrayProducers) {
            $firstArrayPos = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $firstArrayPos = $pi;
                    break;
                }
            }
            if (null !== $firstArrayPos && $firstArrayPos < $funcPos) {
                return match ($argIndex) {
                    0 => $producers[$firstArrayPos],
                    1 => $funcProducer,
                    default => null,
                };
            }
            if (0 === $argIndex) {
                return $funcProducer;
            }
            for ($j = $funcPos + 1, $n = \count($producers); $j < $n; ++$j) {
                if ($producers[$j] instanceof Op\Expr\Array_) {
                    return $producers[$j];
                }
            }

            return null;
        }
        if (
            \count($arrayProducers) === 2
            && 2 === \count($producers)
            && null === $funcProducer
        ) {
            return $producers[$argIndex] ?? null;
        }

        return null;
    }

}
