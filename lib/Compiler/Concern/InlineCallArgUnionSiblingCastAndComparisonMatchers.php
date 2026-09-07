<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg union/sibling/cast/comparison producer matchers (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgSlotResolvers} so gen-0 split-TU can hollow
 * a smaller Concern TU. Mirrors php-src Zend/zend_compile.c call-arg send
 * wiring for array-union Plus, nested Array_ literals, hoisted casts, and
 * comparison/arithmetic inline producers.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgSlotResolvers).
 */
trait InlineCallArgUnionSiblingCastAndComparisonMatchers
{
    /**
     * php-cfg dead call-arg temps for array union may alias a Plus operand, not Plus.result (#10490, #12763).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayUnionPlusInlineCallArgProducer(
        array $producers,
        Operand $callArg,
        int $argCount
    ): ?Op\Expr {
        if ([] === $producers) {
            return null;
        }
        $last = $producers[\count($producers) - 1];
        if (!$last instanceof Op\Expr\BinaryOp\Plus) {
            return null;
        }
        if ($this->operandsReferToSameVariable($last->result, $callArg)) {
            return $last;
        }
        if (1 !== $argCount || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $arrayLiteralCount = 0;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$arrayLiteralCount;
            }
        }
        if ($arrayLiteralCount >= 2) {
            return $last;
        }
        foreach ([$last->left, $last->right] as $operand) {
            if (null !== $operand && $this->operandsReferToSameVariable($operand, $callArg)) {
                return $last;
            }
        }
        foreach ($producers as $producer) {
            if (
                null !== $producer->result
                && $this->operandsReferToSameVariable($producer->result, $callArg)
            ) {
                return $last;
            }
        }

        return null;
    }

    /**
     * Sibling nested inline Array_ literals — map each arg to its outermost producer (#12729, #10230).
     *
     * e.g. array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]])
     * — producers [inner Array_, outer Array_, inner Array_, outer Array_].
     *
     * @param list<Op\Expr> $producers
     */
    private function matchSiblingNestedArrayLiteralCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr {
        $producerCount = \count($producers);
        if ($argCount < 2 || $producerCount <= $argCount) {
            return null;
        }
        if (!$this->producersAreNestedArrayLiteralChain($producers)) {
            return null;
        }
        if (0 !== $producerCount % $argCount) {
            return null;
        }
        $depth = intdiv($producerCount, $argCount);
        if ($depth < 2) {
            return null;
        }
        for ($g = 0; $g < $argCount; ++$g) {
            $group = \array_slice($producers, $g * $depth, $depth);
            if (!$this->arrayProducersFormNestedChain($group)) {
                return null;
            }
        }
        $mappedIndex = $argIndex * $depth + ($depth - 1);

        return $producers[$mappedIndex] ?? null;
    }

    /**
     * php-cfg may omit the first nested arg's inner Array_ when folding into its outer (#10230, #12729).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount,
        array $callArgs = []
    ): ?Op\Expr {
        if (2 !== $argCount || 3 !== \count($producers)) {
            return null;
        }
        $outer0 = $producers[0] ?? null;
        $inner1 = $producers[1] ?? null;
        $outer1 = $producers[2] ?? null;
        if (
            !$outer0 instanceof Op\Expr\Array_
            || !$inner1 instanceof Op\Expr\Array_
            || !$outer1 instanceof Op\Expr\Array_
        ) {
            return null;
        }
        if (!$this->arrayProducersFormNestedChain([$inner1, $outer1])) {
            return null;
        }
        if ($this->cfgExprUsesOperand($inner1, $outer0->result)) {
            return null;
        }
        // array_column([[..]], 'col') — single nested haystack + embedded scalar (#13703).
        if (
            $argIndex > 0
            && isset($callArgs[$argIndex])
            && !$this->callArgOperandExpectsArrayProducer($callArgs[$argIndex])
        ) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null !== $soleHoisted && 0 === $soleHoisted && 0 === $argIndex) {
            return $outer1;
        }

        return 0 === $argIndex ? $outer0 : $outer1;
    }

    /**
     * php-cfg dead call-arg temps that share a result with an inline BitwiseOr/Array_ producer (#11407, #11586).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchDirectResultInlineCallArgProducer(array $producers, Operand $callArg): ?Op\Expr
    {
        foreach (array_reverse($producers) as $producer) {
            if (null === $producer->result) {
                continue;
            }
            // php-cfg parseArg clones leave distinct temps with producer listed in Temporary->ops (#22753).
            $opsMatch = isset($callArg->ops)
                && \is_array($callArg->ops)
                && \in_array($producer, $callArg->ops, true);
            if (!$this->operandsReferToSameVariable($producer->result, $callArg) && !$opsMatch) {
                continue;
            }
            if (
                $producer instanceof Op\Expr\BinaryOp\BitwiseOr
                || $producer instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $producer instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                // Array builtins must not bind to int bitmask OR (#10474, is_array(file(..., FLAGS))).
                if (!$this->callArgOperandExpectsArrayProducer($callArg)) {
                    return $producer;
                }
                continue;
            }
            if (
                $producer instanceof Op\Expr\Array_
                && $this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return $producer;
            }
            // var_export(C::__set_state([]), true) — arg #0 is StaticCall/MethodCall, not nested Array_ (#11896).
            if (
                $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
            ) {
                return $producer;
            }
            // array_merge((object)[...], [...]) — Cast producer feeds arg #0 (#15207).
            if ($producer instanceof Op\Expr\Cast) {
                return $producer;
            }
            // var_export(!$o, true) / var_dump(~$x) — unary not feeds arg #0 (#26702, #10537).
            if ($producer instanceof Op\Expr\BooleanNot || $producer instanceof Op\Expr\BitwiseNot) {
                return $producer;
            }
            // E::A->name / E::A?->name / M::X?->id() — property/method fetch result is the call arg (#9684, #10286).
            if ($producer instanceof Op\Expr\PropertyFetch
                || $producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall) {
                return $producer;
            }
        }

        return null;
    }

    /**
     * var_export([array_any([], fn), array_all([], fn)]) — prefer embedded Array_ over sibling FuncCall (#14516).
     */
    private function preferEmbeddedArrayLiteralOverSiblingFuncCallMatch(
        ?Op\Expr $matched,
        Op $cfgCallOp,
        int $argIndex,
        Block $block,
        Operand $callArgProbe
    ): ?Op\Expr {
        if (
            ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall)
            && $this->callArgOperandExpectsArrayProducer($callArgProbe)
        ) {
            $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
            if (
                0 === $argIndex
                && (
                    'array_combine' === $callee
                    || \in_array($callee, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                )
            ) {
                return $matched;
            }
            $embeddedArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, $argIndex, $block);
            if ($embeddedArray instanceof Op\Expr\Array_) {
                return $embeddedArray;
            }
        }

        return $matched;
    }

    /**
     * array_merge((object)[...], [...]) — hoisted Cast feeds arg #0, not stmt-before Array_ (#15858).
     * array_walk((object)[...], fn) — same for by-ref arg #0 (#15874).
     *
     * Nested casts inside a later array arg must not claim arg #0:
     * array_replace_recursive(['a'=>['b'=>1]], ['a'=>(object)['c'=>2]]) (#25098).
     *
     * @param list<Op\Node> $cfgChildren
     */
    private function inlineCallArgZeroFedByHoistedCastProducer(array $cfgChildren, Op $callOp): bool
    {
        if (!\in_array(
            $this->resolveCfgFuncCallName($callOp),
            [
                'array_merge',
                'array_merge_recursive',
                'array_replace',
                'array_replace_recursive',
                'array_diff',
                'array_intersect',
                'array_diff_key',
                'array_intersect_key',
                'array_walk',
                'array_walk_recursive',
            ],
            true
        )) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp);
        $callArg0 = $callOp->args[0] ?? null;
        foreach ($producers as $producer) {
            if (
                $producer instanceof Op\Expr\Cast
                && $this->hoistedCastFeedsCallArgZero($producer, $callArg0, $producers)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a hoisted Cast is the top-level value of call arg #0 — not an array element (#25098).
     *
     * @param list<Op\Expr> $producers
     */
    private function hoistedCastFeedsCallArgZero(Op\Expr\Cast $cast, mixed $callArg0, array $producers): bool
    {
        if (
            $callArg0 instanceof Operand
            && null !== $cast->result
            && $this->operandsReferToSameVariable($cast->result, $callArg0)
        ) {
            return true;
        }
        // Cast embedded as an Array_ value (nested (object)[...]) is not arg #0 (#25098).
        if (null !== $cast->result) {
            foreach ($producers as $producer) {
                if (
                    $producer instanceof Op\Expr\Array_
                    && $this->cfgExprUsesOperand($producer, $cast->result)
                ) {
                    return false;
                }
            }
        }

        // Top-level (object)[...] — Cast precedes trailing Array_ args; operand identity may differ (#15858).
        return true;
    }

    /**
     * array_merge((object)[...], [...]) — wire hoisted Cast result to arg #0 (#15207, #15858).
     * array_walk((object)[...], fn) — same for by-ref arg #0 (#15874).
     * array_keys((array)$ao) — Cast over ctor INIT_ARRAY stolen by array_keys Array_ path (#28822).
     */
    private function resolveHoistedCastInlineCallArgZeroSlot(
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName,
        int $argIndex
    ): ?string {
        if (0 !== $argIndex || null === $cfgCallOp) {
            return null;
        }
        $callee = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        // null callee: variable `$fn((object)…)` still needs Cast→arg0 (#15858).
        // Reject only when the name is a known non-allowlisted string (#22894 object invoke
        // has null name and is filtered by contiguous-producer scan below).
        if (
            null !== $callee
            && !\in_array(
                $callee,
                [
                    'array_merge',
                    'array_merge_recursive',
                    'array_replace',
                    'array_replace_recursive',
                    'array_diff',
                    'array_intersect',
                    'array_diff_key',
                    'array_intersect_key',
                    'array_walk',
                    'array_walk_recursive',
                    // array_keys((array)$ao) — prefer Cast over ctor INIT_ARRAY (#28822).
                    'array_keys',
                ],
                true
            )
        ) {
            return null;
        }
        $callArg0 = $cfgCallOp->args[0] ?? null;
        // Only dead inline temps from `(object)[...]` / casts — never literals or CVs (#22894).
        if (
            !$callArg0 instanceof Operand
            || $this->isEmbeddedCallLiteralArg($callArg0)
            || !$this->callArgIsDeadInlineTemporary($callArg0)
        ) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            $containing = $this->findCfgBlockContainingExpr($cfgCallOp);
            if (null !== $containing) {
                $cfgChildren = $containing->children;
                foreach ($cfgChildren as $i => $child) {
                    if ($child === $cfgCallOp) {
                        $callIndex = $i;
                        break;
                    }
                }
            }
        }
        if (null === $callIndex || [] === $cfgChildren) {
            return null;
        }
        // Only contiguous inline producers immediately before the call (Echo/Terminal breaks).
        // Stmt-level `(string)$obj` then `$obj($arg)` must not steal the Cast (#22894).
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $cfgCallOp);
        $cast = null;
        // Prefer the Cast nearest the call (last in oldest-first producers) so
        // array_keys((array)(object)[...]) binds Array_ cast, not Object_ (#28822).
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Cast) {
                $cast = $producer;
            }
        }
        if (null === $cast) {
            // array_merge((object)[...], [...]) — Cast may sit behind immediate Array_ (#15858).
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $cfgChildren[$i] ?? null;
                if ($child instanceof Op\Expr\Array_ && $i === $callIndex - 1) {
                    continue;
                }
                if ($child instanceof Op\Expr\Cast) {
                    $cast = $child;
                    break;
                }
                break;
            }
        }
        if (null === $cast) {
            return null;
        }
        if (!$this->hoistedCastFeedsCallArgZero($cast, $callArg0, $producers)) {
            // Nested (object) inside a later array arg — do not steal arg #0 (#25098).
            return null;
        }
        if (null === $block->slotForOperand($cast->result)) {
            foreach ($this->compileExpr($cast, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($cast->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        if (
            0 === $argIndex
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_column', 'preg_replace_callback_array'],
                true
            )
        ) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            $callArg instanceof Operand
            && $this->callArgOperandExpectsArrayProducer($callArg)
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                if (
                    0 === $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                ) {
                    // array_merge(array_keys(...), [...]) — trailing Array_ is arg #1 (#12450, #16418).
                } else {
                    return false;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        if ([] === $producers) {
            return false;
        }
        $producerMatch = $this->matchInlineCallArgProducer(
            $producers,
            $cfgCallOp->args ?? [],
            $argIndex,
            $cfgCallOp,
            $block,
            $this->resolveCfgFuncCallName($cfgCallOp)
        );

        return $producerMatch instanceof Op\Expr\FuncCall
            || $producerMatch instanceof Op\Expr\NsFuncCall
            || $producerMatch instanceof Op\Expr\MethodCall
            || $producerMatch instanceof Op\Expr\StaticCall
            || $producerMatch instanceof Op\Expr\Cast;
    }

    /**
     * var_export(C::__set_state([]), true) — nested Array_ is not arg #0 when a sibling call feeds the dead temp (#11896).
     *
     * @param list<Op\Expr> $producers
     */
    private function preferSiblingCallOverNestedArrayInlineMatch(
        ?Op\Expr $matched,
        array $producers,
        ?Operand $callArgProbe
    ): ?Op\Expr {
        if (
            !$matched instanceof Op\Expr\Array_
            || null === $callArgProbe
            || $this->callArgOperandExpectsArrayProducer($callArgProbe)
        ) {
            return $matched;
        }
        foreach ($producers as $candidate) {
            if (
                ($candidate instanceof Op\Expr\StaticCall
                    || $candidate instanceof Op\Expr\MethodCall
                    || $candidate instanceof Op\Expr\FuncCall
                    || $candidate instanceof Op\Expr\NsFuncCall)
                && null !== $candidate->result
                && $this->operandsReferToSameVariable($candidate->result, $callArgProbe)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * php-cfg dead temps for `var_export(C::AR[0] === E::X, true)` — Identical feeds arg 0, not ClassConstFetch (#5901, #9660).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchBooleanBinaryOpInlineCallArgProducer(array $producers, Operand $callArg): ?Op\Expr
    {
        $comparisonProducers = [];
        foreach ($producers as $producer) {
            if (!$this->isComparisonInlineCallArgProducer($producer)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                return $producer;
            }
            $comparisonProducers[] = $producer;
        }
        if (1 === \count($comparisonProducers) && $this->callArgIsDeadInlineTemporary($callArg)) {
            return $comparisonProducers[0];
        }

        return null;
    }

    /** Hoisted compare/spaceship/relational Expr feeds a call arg, not an inner operand (#13694, #13945). */
    private function isComparisonInlineCallArgProducer(mixed $expr): bool
    {
        if (!$expr instanceof Op\Expr) {
            return false;
        }

        return $expr instanceof Op\Expr\BinaryOp\Identical
            || $expr instanceof Op\Expr\BinaryOp\NotIdentical
            || $expr instanceof Op\Expr\BinaryOp\Equal
            || $expr instanceof Op\Expr\BinaryOp\NotEqual
            || $expr instanceof Op\Expr\BinaryOp\Spaceship
            || $expr instanceof Op\Expr\BinaryOp\Smaller
            || $expr instanceof Op\Expr\BinaryOp\Greater
            || $expr instanceof Op\Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Op\Expr\BinaryOp\GreaterOrEqual
            || $expr instanceof Op\Expr\InstanceOf_
            || $expr instanceof Op\Expr\In_;
    }

    /**
     * php-cfg dead temps for inline arithmetic/bitwise before a consumer call arg (#17210, #17562).
     */
    private function isArithmeticInlineCallArgProducer(mixed $expr): bool
    {
        return $expr instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Op\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Op\Expr\BinaryOp\BitwiseXor
            || $expr instanceof Op\Expr\BinaryOp\Plus
            || $expr instanceof Op\Expr\BinaryOp\Minus
            || $expr instanceof Op\Expr\BinaryOp\Mul
            || $expr instanceof Op\Expr\BinaryOp\Div
            || $expr instanceof Op\Expr\BinaryOp\Mod
            || $expr instanceof Op\Expr\BinaryOp\Pow
            || $expr instanceof Op\Expr\BinaryOp\ShiftLeft
            || $expr instanceof Op\Expr\BinaryOp\ShiftRight;
    }

    /**
     * php-cfg dead temps for `var_export($o->p)` / `var_export($a[0])` / `var_export($o->x++)`
     * — immediate prelude feeds arg #0 (#17540, #26491 / re-#10123).
     *
     * Do not list ConcatList / BinaryOp\Concat here: resolvePrecedingExpressionPreludeCallArgSlot
     * and sibling producers compile via compileExpr(), which does not lower ConcatList (#26489).
     * Encapsed skip lives in rewireVarExportNestedInlineCallArgSendSlots instead.
     */
    private function isImmediateVarExportExpressionPrelude(mixed $expr): bool
    {
        if (!$expr instanceof Op\Expr) {
            return false;
        }

        return $expr instanceof Op\Expr\PropertyFetch
            || $expr instanceof Op\Expr\NullsafePropertyFetch
            || $expr instanceof Op\Expr\StaticPropertyFetch
            || $expr instanceof Op\Expr\ArrayDimFetch
            || $expr instanceof Op\Expr\Cast
            || $expr instanceof Op\Expr\BooleanNot
            || $expr instanceof Op\Expr\BitwiseNot
            || $expr instanceof Op\Expr\UnaryMinus
            || $expr instanceof Op\Expr\UnaryPlus
            || $expr instanceof Op\Expr\PostInc
            || $expr instanceof Op\Expr\PreInc
            || $expr instanceof Op\Expr\PostDec
            || $expr instanceof Op\Expr\PreDec
            || $expr instanceof Op\Expr\Include_
            || $expr instanceof Op\Expr\Eval_
            || $this->isComparisonInlineCallArgProducer($expr)
            || $this->isArithmeticInlineCallArgProducer($expr);
    }

    /**
     * php-cfg hoists Array_/FuncCall operands before a sibling compare feeding var_export arg (#17277).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedExprFeedsSiblingComparisonBeforeCall(
        Op\Expr $expr,
        int $exprIndex,
        int $callIndex,
        array $cfgChildren
    ): bool {
        for ($j = $exprIndex + 1; $j < $callIndex; ++$j) {
            $scan = $cfgChildren[$j] ?? null;
            if (
                $this->isComparisonInlineCallArgProducer($scan)
                && null !== $expr->result
                && $this->cfgExprUsesOperand($scan, $expr->result)
            ) {
                return true;
            }
            if ($scan instanceof Op\Expr\ConstFetch || $scan instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * php-cfg dead ClassConstFetch preludes before inline Array_/Concat call args (#5933, #4109).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterDeadClassConstFetchInlineProducers(array $producers): array
    {
        if (count($producers) < 2) {
            return $producers;
        }
        $filtered = [];
        foreach ($producers as $i => $producer) {
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $feedsLater = false;
                for ($j = $i + 1, $n = count($producers); $j < $n; ++$j) {
                    if ($this->cfgExprUsesOperand($producers[$j], $producer->result)) {
                        $feedsLater = true;
                        break;
                    }
                }
                if ($feedsLater) {
                    continue;
                }
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }
}
