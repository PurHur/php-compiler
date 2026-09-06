<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Hoisted unary / assign-in-call / dead-temp / inline producer call-arg slots (#36387 / prior #36147).
 *
 * Extracted from {@see AdjacentNestedCallArgSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see slotForImmediateUnaryHoistedCallArg}
 * through {@see inlineHoistedProducerForCallArgIndex}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as AdjacentNestedCallArgSlots).
 */
trait HoistedUnaryAssignAndInlineProducerCallArgSlots
{
    /**
     * explode(..., -N) / preg_split(..., -N) / substr(..., -N) — immediate UnaryMinus/Plus prelude (#13424).
     */
    private function slotForImmediateUnaryHoistedCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName
    ): ?string {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $name = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        $unaryArg = match ($name) {
            'explode' => 2,
            'preg_split' => 2,
            // preg_replace_callback_array([...=>fn()], $subj, -1[, &$count]) — UnaryMinus is limit (#19697)
            'preg_replace_callback_array' => 2,
            'fseek' => 1,
            default => null,
        };
        if (\in_array($name, ['substr', 'mb_substr', 'mb_strcut'], true)) {
            $nonEmbedded = [];
            foreach ($cfgCallOp->args as $i => $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    $nonEmbedded[] = (int) $i;
                }
            }
            if (\in_array($argIndex, $nonEmbedded, true) && 1 === \count($nonEmbedded)) {
                $unaryArg = $argIndex;
            }
        }
        if (null === $unaryArg || $argIndex !== $unaryArg) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if ('fseek' === $name && 1 === $argIndex) {
            $immediate = $block->orig->children[$callIndex - 2] ?? null;
        }
        if (!$immediate instanceof Op\Expr\UnaryMinus && !$immediate instanceof Op\Expr\UnaryPlus) {
            return null;
        }
        $slot = $block->slotForOperand($immediate->result);
        if (null === $slot) {
            foreach ($this->compileExpr($immediate, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($immediate->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** fseek($stream, -N, SEEK_*) — ConstFetch whence is the immediate prelude before the call (#16523). */
    private function slotForFseekWhenceHoistedCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName
    ): ?string {
        $name = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('fseek' !== $name || 2 !== $argIndex || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $immediate = $block->orig->children[$callIndex - 1] ?? null;
        if (!$immediate instanceof Op\Expr\ConstFetch) {
            return null;
        }
        $slot = $block->slotForOperand($immediate->result);
        if (null === $slot) {
            foreach ($this->compileExpr($immediate, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($immediate->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /** php-cfg dead call-arg slot — Temporary or unnamed inferred Variable wrapper (#10917). */
    private function callArgIsDeadInlineTemporary(?Operand $arg): bool
    {
        if (null === $arg) {
            return false;
        }
        $cacheKey = spl_object_id($arg);
        if (\array_key_exists($cacheKey, $this->callArgIsDeadInlineTemporaryCache)) {
            return $this->callArgIsDeadInlineTemporaryCache[$cacheKey];
        }
        // Hot path: most php-cfg call-arg temps are Operand\Temporary (#36387).
        if ($arg instanceof Operand\Temporary) {
            // Bare named locals are SSA temps cloned for call-site flags (#8560) — not dead inline.
            $result = null === Block::resolveVariableName($arg);
            $this->callArgIsDeadInlineTemporaryCache[$cacheKey] = $result;

            return $result;
        }
        if ($this->isNamedVariableOperand($arg)) {
            $this->callArgIsDeadInlineTemporaryCache[$cacheKey] = false;

            return false;
        }
        if (null !== Block::resolveVariableName($arg)) {
            // preg_match(..., $m, PREG_OFFSET_CAPTURE) — by-ref named local must not map to hoisted ConstFetch (#13714).
            $this->callArgIsDeadInlineTemporaryCache[$cacheKey] = false;

            return false;
        }
        $result = $arg instanceof Operand\Variable && !$this->isNamedVariableOperand($arg);
        $this->callArgIsDeadInlineTemporaryCache[$cacheKey] = $result;

        return $result;
    }

    /** php-cfg embeds hoisted `($v = expr)` assign-in-call in the call-arg Temporary (#18524). */
    private function callArgIsAssignInCallOperand(?Operand $arg): bool
    {
        if (!$arg instanceof Operand\Temporary) {
            return false;
        }
        // Bare named locals are SSA temps cloned for call-site flags (#8560); their ops[] still
        // list the CV Assign writer — that is not assign-in-call ($v = expr) (#23893).
        if (null !== Block::resolveVariableName($arg)) {
            return false;
        }
        foreach ($arg->ops ?? [] as $embedded) {
            if ($embedded instanceof Op\Expr\Assign) {
                return true;
            }
        }

        return false;
    }

    /**
     * dns_get_record($h, $t = DNS_A | DNS_AAAA) — wire the CV dest, not the stale bitmask temp (#18524).
     */
    private function slotForHoistedAssignInCallNamedDest(Block $block, Op $cfgCallOp): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if (!$prev instanceof Op\Expr\Assign) {
            return null;
        }
        $varRoot = Block::cfgVarRoot($prev->var);
        if (!$varRoot instanceof Operand) {
            return null;
        }
        $namedDest = $block->slotForNamedAssignDest($varRoot);
        if (null === $namedDest) {
            $name = Block::resolveVariableName($varRoot);
            if (null !== $name && '' !== $name) {
                $namedDest = $block->slotIndexForVariableName($name);
            }
        }

        return null !== $namedDest ? (string) $namedDest : null;
    }

    /**
     * Map dead inline bool-arg temps to hoisted !== prelude result slots (#17259).
     */
    private function slotForComparisonPreludeDeadInlineCallArg(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $pendingOps = []
    ): ?string {
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return null;
        }
        $comparisonArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$comparisonArg instanceof Operand || !$this->callArgIsDeadInlineTemporary($comparisonArg)) {
            return null;
        }
        $compareOpcodes = [];
        foreach (array_merge($block->opCodes, $pendingOps) as $op) {
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $compareOpcodes[] = $op;
            }
        }
        if (\count($compareOpcodes) < 2) {
            return null;
        }
        $deadOrdinal = 0;
        foreach ($cfgCallOp->args as $i => $deadArg) {
            if (!$this->callArgIsDeadInlineTemporary($deadArg)) {
                continue;
            }
            if ($i === $argIndex) {
                $target = $compareOpcodes[$deadOrdinal] ?? null;

                return null !== $target && null !== $target->arg1
                    ? (string) $target->arg1
                    : null;
            }
            ++$deadOrdinal;
        }

        return null;
    }

    /**
     * Hoisted producer ordinal among dead inline call-arg temps (skip embedded literals, #10321).
     *
     * @param list<Operand> $callArgs
     */
    private function inlineHoistedProducerSlotIndexForCallArg(
        array $callArgs,
        int $argIndex,
        ?Block $block = null,
        ?Op $cfgCallOp = null
    ): ?int {
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $slot = 0;
        for ($i = 0; $i < $argIndex; ++$i) {
            $arg = $callArgs[$i] ?? null;
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if (
                null !== $block
                && null !== $cfgCallOp
                && property_exists($cfgCallOp, 'args')
                && \is_array($cfgCallOp->args)
            ) {
                $producers = null !== $block->orig
                    ? $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    : [];
                if (
                    $this->callArgUsesInlineArrayNotInHoistedProducers($arg, $block, $cfgCallOp, $i, $producers)
                ) {
                    continue;
                }
            }
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                ++$slot;
            }
        }

        return $slot;
    }

    /**
     * php-cfg hoists inline Expr_Array / ConstFetch siblings before FuncCall — map arg index to producer (#11591, #10321).
     *
     * @param list<Op>        $cfgChildren
     * @param list<Op\Expr>   $producers
     */
    private function inlineHoistedProducerForCallArgIndex(
        Op $callOp,
        int $argIndex,
        array $producers,
        array $cfgChildren,
        ?Block $block = null
    ): ?Op\Expr {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArgs = $callOp->args;
        $callArg = $callArgs[$argIndex] ?? null;
        $mappedArraySplice = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $this->resolveCfgFuncCallName($callOp)
        );
        if (null !== $mappedArraySplice) {
            return $mappedArraySplice;
        }
        $mappedMbstring = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $this->resolveCfgFuncCallName($callOp)
        );
        if (null !== $mappedMbstring) {
            return $mappedMbstring;
        }
        if ($this->producersIncludeInlineArrayUnionPlus($producers)) {
            if (0 === $argIndex) {
                foreach (array_reverse($producers) as $producer) {
                    if ($producer instanceof Op\Expr\BinaryOp\Plus) {
                        return $producer;
                    }
                }
            }

            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        // Exact link before positional guessing: the hoisted argument temporary is a distinct Operand
        // from the producer's ->result, but records that producer as its sole writer, so it names the
        // producer for THIS index directly. Positional mapping handed arg #0 the trailing producer —
        // f($x + 1, $r['k']) printed "K|K" (#23354). Kept inside $producers so this only reorders the
        // candidates this function already considers; the tuned callee-specific matchers above win.
        if (
            $callArg instanceof Operand\Temporary
            && $this->callArgIsDeadInlineTemporary($callArg)
            && 1 === \count($callArg->ops ?? [])
        ) {
            $exactProducer = $callArg->ops[0];
            if (
                $exactProducer instanceof Op\Expr
                && null !== $exactProducer->result
                && \in_array($exactProducer, $producers, true)
            ) {
                return $exactProducer;
            }
        }
        $producerSlotIndex = $this->inlineHoistedProducerSlotIndexForCallArg(
            $callOp->args,
            $argIndex,
            $block,
            $callOp
        );
        if (null === $producerSlotIndex) {
            return null;
        }
        // round(...); fmod(-1.5, …) — immediate UnaryMinus is arg #0, not a prior ConstFetch prelude (#13508).
        if (
            null !== $callIndex
            && $callIndex > 0
            && 0 === $producerSlotIndex
            && 0 === $argIndex
            && $callArg instanceof Operand
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $immediate = $cfgChildren[$callIndex - 1] ?? null;
            if ($immediate instanceof Op\Expr\UnaryMinus || $immediate instanceof Op\Expr\UnaryPlus) {
                $deadHoisted = 0;
                foreach ($callArgs as $hoistedArg) {
                    if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                        ++$deadHoisted;
                    }
                }
                if (1 === $deadHoisted) {
                    return $immediate;
                }
            }
        }
        if (
            null !== $callIndex
            && $callIndex > 0
            && 0 === $producerSlotIndex
            && \count($producers) >= 2
            && \is_array($callArgs)
            && $argIndex === \count($callArgs) - 1
            && $callArg instanceof Operand
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $immediate = $cfgChildren[$callIndex - 1] ?? null;
            $positional = $producers[0] ?? null;
            if (
                $immediate instanceof Op\Expr\ConstFetch
                && $positional instanceof Op\Expr\ConstFetch
                && $immediate !== $positional
                && \in_array($immediate, $producers, true)
            ) {
                $immName = $this->staticNameFromOperand($immediate->name);
                $posName = $this->staticNameFromOperand($positional->name);
                if (
                    null !== $immName
                    && null !== $posName
                    && \in_array(strtolower($immName), ['true', 'false', 'null'], true)
                    && \in_array(strtolower($posName), ['true', 'false', 'null'], true)
                ) {
                    return $immediate;
                }
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $coalesceProducerIndex = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\BinaryOp\Coalesce) {
                $coalesceProducerIndex = $pi;
                break;
            }
        }
        if (null !== $coalesceProducerIndex && $argIndex > 0) {
            $trailingProducers = \array_values(\array_slice($producers, $coalesceProducerIndex + 1));
            $trailingCount = \count($trailingProducers);
            if ($trailingCount < 1) {
                return null;
            }
            $trailingArgs = \array_slice($callOp->args, 1);
            $relativeIndex = $argIndex - 1;
            $trailingSlotIndex = $this->inlineHoistedProducerSlotIndexForCallArg($trailingArgs, $relativeIndex);
            if (null === $trailingSlotIndex || $trailingSlotIndex >= $trailingCount) {
                return null;
            }
            $cfgProducerIndex = $callIndex - $trailingCount + $trailingSlotIndex;
            if ($cfgProducerIndex < 0 || $cfgProducerIndex >= $callIndex) {
                return null;
            }
            $candidate = $cfgChildren[$cfgProducerIndex] ?? null;
            if ($candidate instanceof Op\Expr && \in_array($candidate, $trailingProducers, true)) {
                return $candidate;
            }

            return null;
        }
        $producerCount = \count($producers);
        if ($producerCount < 1 || $producerSlotIndex >= $producerCount) {
            return null;
        }
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $cfgChildren);
        if (null !== $firstSibling) {
            for ($j = $firstSibling; $j < $callIndex; ++$j) {
                $scan = $cfgChildren[$j] ?? null;
                if (!$scan instanceof Op\Expr || !$this->isSiblingInlineCallProducerExpr($scan)) {
                    continue;
                }
                $targetArg = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $j,
                    $callIndex,
                    $cfgChildren
                );
                if (
                    null !== $targetArg
                    && $targetArg === $argIndex
                    && $this->isSiblingMultiArgFuncCallProducer(
                        $scan,
                        $callOp,
                        $j,
                        $callIndex,
                        $cfgChildren
                    )
                ) {
                    return $scan;
                }
            }
            $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $callIndex, $cfgChildren);
            $embeddedArgCount = 0;
            $deadInlineTempCount = 0;
            $hoistedArgCount = 0;
            foreach ($callOp->args as $hoistedArg) {
                if (null !== $hoistedArg && $this->isEmbeddedCallLiteralArg($hoistedArg)) {
                    ++$embeddedArgCount;
                    continue;
                }
                if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                    ++$deadInlineTempCount;
                }
                if (null !== $hoistedArg) {
                    ++$hoistedArgCount;
                }
            }
            $consumerArgCount = \count($callOp->args);
            if (
                \count($outer) === $consumerArgCount
                && (
                    $embeddedArgCount === $consumerArgCount
                    || $deadInlineTempCount === $consumerArgCount
                    || \count($outer) === $hoistedArgCount
                )
                && isset($outer[$argIndex])
            ) {
                $outerProducer = $outer[$argIndex];
                if ($outerProducer instanceof Op\Expr) {
                    return $outerProducer;
                }
            }
        }
        $cfgProducerIndex = $this->inlineCallArgProducerCfgChildIndex(
            $callIndex,
            $producerSlotIndex,
            $producerCount,
            $cfgChildren
        );
        if (null === $cfgProducerIndex) {
            return null;
        }
        $candidate = $cfgChildren[$cfgProducerIndex] ?? null;
        if (!$candidate instanceof Op\Expr || !\in_array($candidate, $producers, true)) {
            $mergeCallee = strtolower($this->resolveCfgFuncCallName($callOp) ?? '');
            if (
                \in_array(
                    $mergeCallee,
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $leadingNested = $this->matchLeadingNestedInlineArrayMergeFamilyCallArgProducer(
                    $producers,
                    $argIndex,
                    \count($callOp->args)
                );
                if (null !== $leadingNested) {
                    return $leadingNested;
                }
            }
            // ConstFetch + BitwiseOr call args with filtered operand ConstFetch preludes (#16152, #11804).
            if ($producerSlotIndex < $producerCount) {
                $comparisonOnly = array_values(array_filter(
                    $producers,
                    fn (Op\Expr $producer): bool => $this->isComparisonInlineCallArgProducer($producer)
                ));
                if (\count($comparisonOnly) === $producerCount) {
                    $chronological = array_reverse($comparisonOnly);
                    $direct = $chronological[$producerSlotIndex] ?? null;
                } else {
                    $direct = $producers[$producerSlotIndex] ?? null;
                }
                if ($direct instanceof Op\Expr) {
                    return $direct;
                }
            }

            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        $stmtCoalesce = $cfgChildren[$callIndex - 1] ?? null;
        if (
            $stmtCoalesce instanceof Op\Expr\BinaryOp\Coalesce
            && null !== $callArg
            && !$this->isCallArgUnrelatedToPriorStmtCoalesce($callArg)
        ) {
            return $stmtCoalesce;
        }
        if (
            $candidate instanceof Op\Expr\Array_
            && null !== $callArg
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
            if (null !== $nestedTrailing) {
                [$arrayChain, ] = $nestedTrailing;
                if (
                    \count($arrayChain) >= 2
                    && $this->arrayProducersFormNestedChain($arrayChain)
                ) {
                    // Nested inline array literal is one call arg — outer Array_ (#11300, #12008).
                    return $arrayChain[\count($arrayChain) - 1];
                }
            }
            if (
                1 === $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($callOp) ?? ''),
                    ['in_array', 'array_search'],
                    true
                )
            ) {
                $haystackProducer = $this->matchInlineArraySearchHaystackProducer($producers, $callArg);
                if ($haystackProducer instanceof Op\Expr\Array_) {
                    return $haystackProducer;
                }
                $constFuncSplit = $this->splitLeadingConstFetchWithFuncCallCallArg($producers);
                if (null !== $constFuncSplit) {
                    [, $funcProducer] = $constFuncSplit;
                    if ($funcProducer instanceof Op\Expr\FuncCall || $funcProducer instanceof Op\Expr\NsFuncCall) {
                        return $funcProducer;
                    }
                }
            }
        }
        if ($candidate instanceof Op\Expr\ClassConstFetch) {
            $nearest = $producers[0] ?? null;
            if (
                ($nearest instanceof Op\Expr\PropertyFetch
                    || $nearest instanceof Op\Expr\NullsafePropertyFetch
                    || $nearest instanceof Op\Expr\NullsafeMethodCall)
                && null !== $candidate->result
                && $this->cfgExprUsesOperand($nearest, $candidate->result)
            ) {
                return $nearest;
            }
        }

        return $candidate;
    }
}
