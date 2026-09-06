<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * Inline call-arg producer slot discovery (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see findInlineArrayProducerForCallArg} and dead-temp / array-producer
 * helpers. Inline expr producer discovery lives in
 * {@see FindInlineExprCallArgProducerSlot}; coalesce / nullsafe call-arg slots
 * live in {@see FindInlineCoalesceAndNullsafeCallArgSlots}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait FindInlineCallArgProducerSlot
{
    /**
     * php-cfg may lower inline Expr_Array / Expr_New results and call/ctor args to distinct
     * temporaries (`f(['a' => 1])`, `new C(['x'])`, `g(new C('x'))`) (#8561).
     *
     * @return ?string producer slot to pass to TYPE_ARG_SEND instead of the empty arg slot
     */
    /**
     * php-cfg hoists enum `case` ClassConstFetch before inline Expr_Array call args (#5721).
     */
    private function findInlineArrayProducerForCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp = null,
        ?int $knownArgIndex = null
    ): ?Op\Expr\Array_
    {
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($cfgChildren, $arg, $cfgCallOp);
        if (null === $callSite && null !== $cfgCallOp && null !== $knownArgIndex) {
            $callSite = [$cfgCallOp, $knownArgIndex];
        }
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $namedMergeCallArg = $callOp->args[$argIndex] ?? $arg;
        if (
            $namedMergeCallArg instanceof Operand
            && null !== Block::resolveVariableName($namedMergeCallArg)
            && !$this->callArgIsDeadInlineTemporary($namedMergeCallArg)
            && \in_array(
                $this->resolveCfgFuncCallName($callOp),
                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                true
            )
        ) {
            // $a = [...]; array_replace_recursive($a, $b) — read assign slots, not trailing hoisted Array_ (#18571).
            return null;
        }
        if ('array_combine' === $this->resolveCfgFuncCallName($callOp)) {
            $combineProducers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp);
            $combineMatch = $this->matchArrayCombineInlineProducers($combineProducers, $argIndex);
            if ($combineMatch instanceof Op\Expr\Array_) {
                return $combineMatch;
            }
            if (
                $combineMatch instanceof Op\Expr\FuncCall
                || $combineMatch instanceof Op\Expr\NsFuncCall
            ) {
                // array_combine(array_keys(...), [...]) — nested FuncCall arg, not stmt-before Array_ (#15857).
                return null;
            }
        }
        if ('substr_replace' === $this->resolveCfgFuncCallName($callOp)) {
            $substrReplaceProducers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            $substrReplaceMatch = $this->matchInlineArrayProducersToArrayCallArgs(
                $substrReplaceProducers,
                $callOp->args,
                $argIndex
            );
            if ($substrReplaceMatch instanceof Op\Expr\Array_) {
                return $substrReplaceMatch;
            }
        }
        if (
            'proc_open' === $this->resolveCfgFuncCallName($callOp)
            && \is_array($callOp->args)
        ) {
            $hoisted = $this->procOpenHoistedInlineArrayForArg($callOp, $argIndex, $block);
            if ($hoisted instanceof Op\Expr\Array_) {
                return $hoisted;
            }
            $procOpenProducers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp);
            $arrayProducers = array_values(array_filter(
                $procOpenProducers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if (0 === $argIndex) {
                $commandArg = $callOp->args[0] ?? null;
                if ($commandArg instanceof Operand) {
                    $embeddedCommand = $this->unwrapArrayLiteralExpr($commandArg);
                    if ($embeddedCommand instanceof Op\Expr\Array_) {
                        return $embeddedCommand;
                    }
                }
            }
            if (\in_array($argIndex, [0, 1], true) && [] !== $arrayProducers) {
                $matched = $this->matchInlineArrayProducersToArrayCallArgs(
                    $procOpenProducers,
                    $callOp->args,
                    $argIndex
                );
                if ($matched instanceof Op\Expr\Array_) {
                    return $matched;
                }
            }
            if (1 === $argIndex && [] !== $arrayProducers) {
                $outer = $this->matchOutermostNestedInlineArrayProducerForCallArg(
                    $procOpenProducers,
                    $arrayProducers,
                    $argIndex,
                    \count($callOp->args)
                );
                if ($outer instanceof Op\Expr\Array_) {
                    return $outer;
                }
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null !== $callIndex) {
            $prev = $cfgChildren[$callIndex - 1] ?? null;
            if ($prev instanceof Op\Expr\ArrayDimFetch) {
                $positionalCallArg = $callOp->args[$argIndex] ?? $arg;
                if (
                    null !== $prev->result
                    && null !== $positionalCallArg
                    && $this->operandsReferToSameVariable($prev->result, $positionalCallArg)
                ) {
                    return null;
                }
            }
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $cfgChildren[$i];
                if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                    break;
                }
                if ($child instanceof Op\Expr\BinaryOp\Plus) {
                    // Plus immediately precedes the call — wire TYPE_ARG_SEND to Plus.result (#10490, #12763).
                    return null;
                }
                if ($child instanceof Op\Expr\Array_) {
                    $callArgProbe = $callOp->args[$argIndex] ?? $arg;
                    // var_export([array_any([], fn), array_all([], fn)]) — stmt-before Array_ (#14516).
                    // new C([...]) in param/static defaultBlocks: php-cfg often leaves the ctor arg
                    // as inferred unknown/mixed while Array_.result is int[] (#22390, #8561).
                    // var_export([...new ArrayIterator([...])]) — unpack Array_ is inferred unknown
                    // while an earlier ctor Array_ sits before New_; bind stmt-before (#24645).
                    // Do not apply the New_ fallback to typed null/scalar dead temps — that steals
                    // the trailing Array_ for `new C(null, [...])` (#22770).
                    $deadTempWantsArray = $this->callArgIsDeadInlineTemporary($callArgProbe)
                        && (
                            $this->callArgOperandExpectsArrayProducer($callArgProbe)
                            || $this->callArgIsDeadUnknownOrMixedTemporary($callArgProbe)
                            || $this->newCtorDeadTempMayBindStmtBeforeArray(
                                $callArgProbe,
                                $callOp,
                                $argIndex,
                                $block
                            )
                        );
                    if (
                        $i === $callIndex - 1
                        && (
                            $this->operandsReferToSameVariable($child->result, $callArgProbe)
                            || $this->operandsReferToSameVariable($child->result, $arg)
                            || (
                                $deadTempWantsArray
                                && !$this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                                    $callOp,
                                    $argIndex,
                                    $block
                                )
                            )
                        )
                    ) {
                        // array_map(null, [[..]]) — stmt-before Array_ is haystack arg #1, not null callback (#15976).
                        if (
                            0 === $argIndex
                            && $this->arrayMapNullCallbackPrecedesInlineHaystack($callOp, $block)
                        ) {
                            continue;
                        }
                        // array_merge((object)[...], [...]) — trailing Array_ is arg #1, not arg #0 (#15858).
                        if (
                            0 === $argIndex
                            && $this->callArgIsDeadInlineTemporary($callArgProbe)
                            && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                            && $this->inlineCallArgZeroFedByHoistedCastProducer($cfgChildren, $callOp)
                        ) {
                            continue;
                        }

                        return $child;
                    }
                    if (
                        $this->operandsReferToSameVariable($child->result, $arg)
                        && $this->callArgOperandExpectsArrayProducer($arg)
                    ) {
                        return $child;
                    }
                    continue;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp);
        if ($this->producersIncludeInlineArrayUnionPlus($producers)) {
            // Array union — Plus.result is the call arg, not a hoisted Array_ (#10490, #13787).
            return null;
        }
        if (
            ($this->callIncludesNamedParameter($callOp) || null !== $this->callArgName($callOp->args[$argIndex] ?? $arg))
            && isset($callOp->args[$argIndex])
        ) {
            $namedCallArg = $callOp->args[$argIndex];
            foreach ($producers as $candidate) {
                if (
                    $candidate instanceof Op\Expr\Array_
                    && null !== $candidate->result
                    && $this->operandsReferToSameVariable($candidate->result, $namedCallArg)
                    && $this->callArgOperandExpectsArrayProducer($namedCallArg)
                ) {
                    return $candidate;
                }
            }
            if ($this->callArgIsDeadInlineTemporary($namedCallArg)) {
                $unassigned = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                    $producers,
                    $callOp,
                    $argIndex,
                    $block
                );
                if (
                    $this->inlineCallArgProducerUsesExprResultSlot($unassigned)
                    && $unassigned instanceof Op\Expr\Array_
                    && $this->callArgOperandExpectsArrayProducer($namedCallArg)
                ) {
                    return $unassigned;
                }
            }
        }
        $positionalCallArg = $callOp->args[$argIndex] ?? $arg;
        if (
            $this->callArgIsDeadInlineTemporary($positionalCallArg)
            && $this->callArgOperandExpectsArrayProducer($positionalCallArg)
        ) {
            // array_combine(array_keys(...), [...]) — sibling FuncCall feeds arg #0, not trailing Array_ (#15558, #15857).
            $arrayCombinePair = 'array_combine' === $this->resolveCfgFuncCallName($callOp)
                && 2 === \count($callOp->args ?? [])
                ? $this->matchArrayCombineInlineProducers($producers, $argIndex)
                : null;
            if (
                $arrayCombinePair instanceof Op\Expr\FuncCall
                || $arrayCombinePair instanceof Op\Expr\NsFuncCall
            ) {
                return null;
            }
            if ($arrayCombinePair instanceof Op\Expr\Array_) {
                return $arrayCombinePair;
            }
            // array_merge((object)[...], [...]) — Cast feeds arg #0, not trailing Array_ (#15207, #15858).
            if (
                0 === $argIndex
                && \in_array(
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
                    ],
                    true
                )
            ) {
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Cast) {
                        return null;
                    }
                }
            }
            $directUnary = $this->matchDirectResultInlineCallArgProducer($producers, $positionalCallArg);
            if ($directUnary instanceof Op\Expr\Cast) {
                return null;
            }
            // array_reverse([...], true) — nested FuncCall feeds the dead temp, not hoisted Array_ (#14042).
            $directCall = $this->matchDirectResultInlineCallArgProducer($producers, $positionalCallArg);
            if (
                $directCall instanceof Op\Expr\FuncCall
                || $directCall instanceof Op\Expr\NsFuncCall
                || $directCall instanceof Op\Expr\StaticCall
                || $directCall instanceof Op\Expr\MethodCall
            ) {
                if (
                    'array_combine' === $this->resolveCfgFuncCallName($callOp)
                    && 0 === $argIndex
                    && null !== $this->matchArrayCombineInlineProducers($producers, $argIndex)
                ) {
                    return null;
                }
                if ($this->callArgOperandExpectsArrayProducer($positionalCallArg)) {
                    // array_slice([..], array_search(...)) — sibling FuncCall prelude must not block Array_ (#13684).
                    $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                    if (null !== $nestedTrailing) {
                        [$arrayChain, $trailing] = $nestedTrailing;
                        if (1 + \count($trailing) === \count($callOp->args) && 0 === $argIndex) {
                            $outer = $arrayChain[\count($arrayChain) - 1] ?? null;
                            if ($outer instanceof Op\Expr\Array_) {
                                return $outer;
                            }
                        }
                    }
                    $arrayProducers = array_values(array_filter(
                        $producers,
                        static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                    ));
                    if ([] !== $arrayProducers && 0 === $argIndex) {
                        if (
                            'array_keys' === $this->resolveCfgFuncCallName($callOp)
                            && !$this->callArgIsCoalesceMergeProducer($positionalCallArg, $block, $callOp, $argIndex)
                        ) {
                            $keysArray = $this->inlineArrayProducerForArrayKeysDeadCallArg(
                                $positionalCallArg,
                                $block,
                                $callOp
                            );
                            if ($keysArray instanceof Op\Expr\Array_) {
                                return $keysArray;
                            }
                        }
                        if ('array_combine' === $this->resolveCfgFuncCallName($callOp)) {
                            $combineMatch = $this->matchArrayCombineInlineProducers($producers, $argIndex);
                            if ($combineMatch instanceof Op\Expr\FuncCall || $combineMatch instanceof Op\Expr\NsFuncCall) {
                                return null;
                            }
                        }
                        if (
                            \in_array(
                                strtolower($this->resolveCfgFuncCallName($callOp) ?? ''),
                                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                                true
                            )
                            && \count($callOp->args ?? []) >= 2
                        ) {
                            $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall($cfgChildren, $callOp);
                            $mergeMapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers($mergeProducers, $argIndex);
                            if (null === $mergeMapped) {
                                $mergeMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                                    $mergeProducers,
                                    $argIndex,
                                    \count($callOp->args ?? []),
                                    $callOp->args ?? []
                                );
                            }
                            if (
                                $mergeMapped instanceof Op\Expr\FuncCall
                                || $mergeMapped instanceof Op\Expr\NsFuncCall
                            ) {
                                return null;
                            }
                            if ($mergeMapped instanceof Op\Expr\Array_) {
                                return $mergeMapped;
                            }
                        }
                        return $arrayProducers[\count($arrayProducers) - 1];
                    }
                }
                $embedded = $this->unwrapArrayLiteralExpr($positionalCallArg);
                if (null === $embedded) {
                    $embeddedProducer = $this->findCfgProducerExprForOperand($positionalCallArg);
                    if ($embeddedProducer instanceof Op\Expr\Array_) {
                        $embedded = $embeddedProducer;
                    }
                }
                if ($embedded instanceof Op\Expr\Array_) {
                    return $embedded;
                }

                if ($this->inlineCallArgProducerFeedsCallArgOp($directCall, $callOp, $positionalCallArg)) {
                    return null;
                }

                return null;
            }
            if (
                \in_array(
                    $this->resolveCfgFuncCallName($callOp),
                    [
                        'array_merge',
                        'array_merge_recursive',
                        'array_replace',
                        'array_replace_recursive',
                    ],
                    true
                )
                && \count($callOp->args ?? []) >= 2
            ) {
                $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall($cfgChildren, $callOp);
                $mergeMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                    $mergeProducers,
                    $argIndex,
                    \count($callOp->args ?? []),
                    $callOp->args ?? []
                );
                if ($mergeMapped instanceof Op\Expr\Array_) {
                    return $mergeMapped;
                }
            }
            $unassigned = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                $producers,
                $callOp,
                $argIndex,
                $block
            );
            if ($unassigned instanceof Op\Expr\Array_) {
                return $unassigned;
            }
            if (0 === $argIndex) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, $trailing] = $nestedTrailing;
                    if (
                        1 + \count($trailing) === \count($callOp->args)
                        && !$this->arrayMapNullCallbackPrecedesInlineHaystack($callOp, $block)
                        && !($trailing[0] ?? null) instanceof Op\Expr\New_
                    ) {
                        $outer = $arrayChain[\count($arrayChain) - 1] ?? null;
                        if ($outer instanceof Op\Expr\Array_) {
                            return $outer;
                        }
                    }
                }
                $arrayProducers = array_values(array_filter(
                    $producers,
                    static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                ));
                if ([] !== $arrayProducers && !$this->arrayMapNullCallbackPrecedesInlineHaystack($callOp, $block)) {
                    if (
                        'array_combine' === $this->resolveCfgFuncCallName($callOp)
                        && 0 === $argIndex
                    ) {
                        $combineMatch = $this->matchArrayCombineInlineProducers($producers, $argIndex);
                        if ($combineMatch instanceof Op\Expr\FuncCall || $combineMatch instanceof Op\Expr\NsFuncCall) {
                            return null;
                        }
                    }
                    // attachIterator(new ArrayIterator([...]), …) — Array_ feeds inner ctor, not arg #0 (#13342, #13685).
                    $lastArray = $arrayProducers[\count($arrayProducers) - 1];
                    $lastIdx = array_search($lastArray, $producers, true);
                    if (
                        false !== $lastIdx
                        && ($producers[$lastIdx + 1] ?? null) instanceof Op\Expr\New_
                    ) {
                        return null;
                    }
                    return $arrayProducers[\count($arrayProducers) - 1];
                }
            }
        }
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if ($producer instanceof Op\Expr\Array_) {
            $producerIdx = array_search($producer, $producers, true);
            if (
                false !== $producerIdx
                && ($producers[$producerIdx + 1] ?? null) instanceof Op\Expr\New_
            ) {
                return null;
            }
            if (0 === $argIndex && !$this->callIncludesNamedParameter($callOp)) {
                $arrayProducers = array_values(array_filter(
                    $producers,
                    static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                ));
                if (
                    \count($arrayProducers) >= 2
                    && $this->producersAreNestedArrayLiteralChain($arrayProducers)
                    && $this->arrayProducersFormNestedChain($arrayProducers)
                ) {
                    return $arrayProducers[\count($arrayProducers) - 1];
                }
            }

            $callArg = $callOp->args[$argIndex] ?? $arg;
            if (
                null !== $callArg
                && !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                    $producers,
                    $argIndex,
                    \count($callOp->args),
                    \count($producers)
                );
                if (null !== $outerArray) {
                    return $outerArray;
                }
                // var_export(current([1,2]), true) — arg #0 is FuncCall result, not ephemeral Array_ (#10654).
                return null;
            }

            return $producer;
        }

        $callArg = $callOp->args[$argIndex] ?? $arg;
        if ($callArg instanceof Operand && $this->callArgOperandExpectsArrayProducer($callArg)) {
            $embedded = $this->unwrapArrayLiteralExpr($callArg);
            if (null === $embedded) {
                $embeddedProducer = $this->findCfgProducerExprForOperand($callArg);
                if ($embeddedProducer instanceof Op\Expr\Array_) {
                    $embedded = $embeddedProducer;
                }
            }
            if ($embedded instanceof Op\Expr\Array_) {
                return $embedded;
            }
        }

        return null;
    }

    /**
     * php-cfg dead call-arg temps for named parameters — map to hoisted Array_ not assigned to a named local (#11170).
     *
     * @param list<Op\Expr> $producers
     */
    private function findUnassignedInlineArrayProducerForDeadCallArg(
        array $producers,
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $deadArrayArgIndices = [];
        foreach ($callOp->args as $idx => $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg) || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->callArgOperandExpectsArrayProducer($callArg)) {
                $deadArrayArgIndices[] = (int) $idx;
            }
        }
        $positionAmongDeadArrays = array_search($argIndex, $deadArrayArgIndices, true);
        if (false === $positionAmongDeadArrays) {
            return null;
        }
        $unassigned = [];
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\Array_) {
                continue;
            }
            if ($this->inlineProducerAssignedToNamedLocalBeforeCall($producer, $callOp, $block)) {
                continue;
            }
            $unassigned[] = $producer;
        }
        if ([] === $unassigned) {
            return null;
        }
        if (1 === \count($deadArrayArgIndices)) {
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                return $immediate;
            }

            return $unassigned[\count($unassigned) - 1];
        }
        // Nested / sibling inline Array_ chains — flat unassigned[] index is wrong (#12729, #12730).
        $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if (
            $matched instanceof Op\Expr\FuncCall
            || $matched instanceof Op\Expr\NsFuncCall
            || $matched instanceof Op\Expr\StaticCall
            || $matched instanceof Op\Expr\MethodCall
            || $matched instanceof Op\Expr\Cast
        ) {
            return $matched;
        }
        if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
            return $matched;
        }

        return $unassigned[$positionAmongDeadArrays] ?? null;
    }

    /** Hoisted inline call-arg producers whose Expr::result is the callee operand (#10490, #12763). */
    private function inlineCallArgProducerUsesExprResultSlot(?Op\Expr $matched): bool
    {
        return $matched instanceof Op\Expr\Cast
            || $matched instanceof Op\Expr\BooleanNot
            || $matched instanceof Op\Expr\BitwiseNot
            || $matched instanceof Op\Expr\Array_
            || $matched instanceof Op\Expr\BinaryOp\Plus
            || $matched instanceof Op\Expr\BinaryOp\Concat
            || $this->isComparisonInlineCallArgProducer($matched);
    }

    /**
     * Hoisted Array_ preludes followed by Plus — inline array union call arg (#10490, #13787).
     *
     * @param list<Op\Expr> $producers
     */
    private function producersIncludeInlineArrayUnionPlus(array $producers): bool
    {
        $seenArray = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                $seenArray = true;
            } elseif ($seenArray && $producer instanceof Op\Expr\BinaryOp\Plus) {
                return true;
            }
        }

        return false;
    }

    /** True when hoisted producers before $callOp include array union / concat (#12763, re-#10578). */
    private function precedingInlineCallArgHasPlusOrConcatProducer(array $cfgChildren, Op $callOp): bool
    {
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp) as $producer) {
            if ($producer instanceof Op\Expr\BinaryOp\Plus
                || $producer instanceof Op\Expr\BinaryOp\Concat
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dead php-cfg call-arg temps that expect hoisted Array_ producers (#11586, #12730).
     *
     * @param Op\Expr\FuncCall|Op\Expr\NsFuncCall|Op $callOp
     */
    private function countDeadArrayInlineCallArgs(Op $callOp): int
    {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return 0;
        }
        $count = 0;
        foreach ($callOp->args as $callArg) {
            if (!$this->callArgIsDeadInlineTemporary($callArg) || $this->isEmbeddedCallLiteralArg($callArg)) {
                continue;
            }
            if ($this->callArgOperandExpectsArrayProducer($callArg)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Named `command: [...]` style dead temps — last hoisted Array_ between call and prior Assign (#11170).
     */
    private function resolveNamedDeadTempArrayCallArgSlot(Op $callOp, Block $block): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $lastArray = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Assign || $child instanceof Op\Expr\AssignRef) {
                break;
            }
            if ($child instanceof Op\Expr\Array_) {
                $lastArray = $child;
            }
        }
        if (null === $lastArray) {
            return null;
        }
        if (null === $block->slotForOperand($lastArray->result)) {
            foreach ($this->compileExpr($lastArray, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($lastArray->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function inlineProducerAssignedToNamedLocalBeforeCall(
        Op\Expr $producer,
        Op $callOp,
        Block $block
    ): bool {
        if (null === $block->orig || null === $producer->result) {
            return false;
        }
        $children = $block->orig->children;
        $producerIndex = null;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $producer) {
                $producerIndex = $i;
            }
            if ($child === $callOp) {
                $callIndex = $i;
            }
        }
        if (null === $producerIndex || null === $callIndex || $producerIndex >= $callIndex) {
            return false;
        }
        for ($i = $producerIndex + 1; $i < $callIndex; ++$i) {
            $stmt = $children[$i];
            if (
                $stmt instanceof Op\Expr\Assign
                && $this->operandsReferToSameVariable($stmt->expr, $producer->result)
                && $this->isNamedVariableOperand($stmt->var)
            ) {
                return true;
            }
        }

        return false;
    }

    /** Dead php-cfg call-arg temp whose inferred type is array-shaped (incl. `string[]`, #11170). */
    private function callArgOperandExpectsArrayProducer(Operand $callArg): bool
    {
        $root = $this->unwrapOperandChain($callArg);
        if (null === $root->type || !method_exists($root->type, 'toString')) {
            return false;
        }
        $repr = $root->type->toString();
        if ('array' === $repr) {
            return true;
        }
        if (str_ends_with($repr, '[]')) {
            return true;
        }
        // Union/intersection builtins (proc_open array|string, etc.) may pass inline Expr_Array (#13734).
        return (bool) preg_match('/\barray\b/', $repr);
    }

    /**
     * Dead ctor arg with unknown/mixed (or absent) type — #22390 `new C([...])` defaults.
     * Typed null/int/… must not use the stmt-before Array_ fallback (#22770).
     */
    private function callArgIsDeadUnknownOrMixedTemporary(Operand $callArg): bool
    {
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if (null === $root->type || !method_exists($root->type, 'toString')) {
            return true;
        }
        $repr = $root->type->toString();

        return \in_array($repr, ['unknown', 'mixed'], true);
    }

    /**
     * Whether a New_ dead arg may bind the immediate stmt-before Array_ (#22390).
     * Rejects typed non-array temps and args whose multi-arg producer match is not Array_ (#22770).
     */
    private function newCtorDeadTempMayBindStmtBeforeArray(
        Operand $callArg,
        Op $callOp,
        int $argIndex,
        Block $block
    ): bool {
        if (!$callOp instanceof Op\Expr\New_ || !$this->callArgIsDeadUnknownOrMixedTemporary($callArg)) {
            return false;
        }
        if (null === $block->orig || !\is_array($callOp->args) || \count($callOp->args) < 2) {
            return true;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        if (\count($producers) < 2) {
            return true;
        }
        $matched = $this->matchInlineCallArgProducer(
            $producers,
            $callOp->args,
            $argIndex,
            $callOp,
            $block
        );

        return !$matched instanceof Op\Expr || $matched instanceof Op\Expr\Array_;
    }

    /**
     * Dead call-arg temp whose php-cfg type is unknown/mixed, fed by stmt-before Array_ of
     * ClassConstFetch values (enum cases). Typed int[]/string[] arrays already hit
     * {@see callArgOperandExpectsArrayProducer()}; enum-case arrays stay inferred:unknown so
     * ARG_SEND would otherwise steal the first ClassConstFetch (#19786).
     */
    private function callArgIsDeadUntypedInlineArrayOfClassConst(
        Operand $callArg,
        Op $cfgCallOp,
        Block $block,
        int $argIndex
    ): bool {
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if ($this->callArgOperandExpectsArrayProducer($callArg)) {
            return false;
        }
        $root = $this->unwrapOperandChain($callArg);
        if (null !== $root->type && method_exists($root->type, 'toString')) {
            $repr = $root->type->toString();
            if (!\in_array($repr, ['unknown', 'mixed'], true)) {
                return false;
            }
        }
        // Prefer the stmt-before Array_; with sibling flat literals the ClassConstFetch array
        // may be earlier (call_user_func_array([A::class, 'm'], [...]) — #27139).
        $array = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (
            (!$array instanceof Op\Expr\Array_ || !$this->arrayLiteralHasClassConstFetchValue($array))
            && null !== $block->orig
        ) {
            $array = null;
            foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            ) as $producer) {
                if (
                    $producer instanceof Op\Expr\Array_
                    && $this->arrayLiteralHasClassConstFetchValue($producer)
                ) {
                    $array = $producer;
                    break;
                }
            }
        }
        if (!$array instanceof Op\Expr\Array_ || !$this->arrayLiteralHasClassConstFetchValue($array)) {
            return false;
        }
        $argc = \count($cfgCallOp->args ?? []);
        if (1 === $argc) {
            return 0 === $argIndex;
        }
        if (null === $block->orig) {
            return false;
        }
        // Multi-arg: ClassConstFetch mapped to this arg is an Array_ element ⇒ arg is the Array_
        // (json_encode([E::A]); f([E::A], E::B) arg0). Bare E::A arg keeps ClassConstFetch wiring.
        // Trailing-enum ordinal fallback can also map that same array-element fetch onto a later
        // dead ConstFetch temp (http_build_query([E::A], '', '&', PHP_QUERY_RFC3986) — #23702);
        // only arg #0 is the enum-case array in that case.
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp,
            $block
        );
        $fetch = $this->precedingClassConstFetchForCallArgIndex($cfgCallOp, $argIndex, $fetches);
        if (!$fetch instanceof Op\Expr\ClassConstFetch || null === $fetch->result) {
            return 0 === $argIndex;
        }
        foreach ($array->values as $value) {
            if (null !== $value && $this->operandsReferToSameVariable($value, $fetch->result)) {
                return 0 === $argIndex;
            }
        }

        return false;
    }

    /** @param Op\Expr\Array_ $array */
    private function arrayLiteralHasClassConstFetchValue(Op\Expr\Array_ $array): bool
    {
        foreach ($array->values as $value) {
            if (null === $value) {
                continue;
            }
            $candidates = [$value, $this->unwrapOperandChain($value)];
            foreach ($candidates as $operand) {
                foreach ($operand->ops ?? [] as $op) {
                    if ($op instanceof Op\Expr\ClassConstFetch) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Dedicated array-producer ARG_SEND resolution — haystack-family builtins only (#15612, #14134).
     *
     * Skips mb_detect_order([...]) and other mutators that take a single inline array operand.
     * Multi-array set ops (array_diff/intersect/merge) wire every hoisted array operand (#15947).
     */
    private function shouldUseArrayProducerCallArgResolution(?Op $cfgCallOp, int $argIndex, ?string $calleeName): bool
    {
        if (null === $cfgCallOp || $argIndex < 0) {
            return false;
        }
        $callee = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');

        if (\in_array($callee, [
            'in_array',
            'array_search',
            'array_key_exists',
            'key_exists',
            'array_column',
        ], true)) {
            return $argIndex >= 1;
        }

        return \in_array($callee, [
            'array_combine',
            'array_merge',
            'array_merge_recursive',
            'array_replace',
            'array_replace_recursive',
            'array_diff',
            'array_intersect',
            'array_diff_key',
            'array_intersect_key',
            'array_intersect_assoc',
            'array_diff_assoc',
            'array_udiff',
            'array_uintersect',
            'array_udiff_assoc',
            'array_uintersect_assoc',
            'array_udiff_uassoc',
            'array_uintersect_uassoc',
            'array_diff_uassoc',
            'array_intersect_uassoc',
            'array_diff_ukey',
            'array_intersect_ukey',
        ], true);
    }

    /** Dead array temp that needs haystack-family producer wiring (#15612), not embedded literal (#14134). */
    private function callArgUsesHaystackFamilyArrayProducerResolution(
        ?Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName,
        Operand $arg
    ): bool {
        if ($this->callArgUnpack($arg)) {
            return false;
        }

        return $this->callArgIsDeadInlineTemporary($arg)
            && $this->callArgOperandExpectsArrayProducer($arg)
            && $this->shouldUseArrayProducerCallArgResolution($cfgCallOp, $argIndex, $calleeName);
    }

    /** Haystack-family dead inline temp — prefer dim-fetch / array producer slots over echo concat (#17000). */
    private function callArgIsDeadInlineHaystackFamilySlot(
        ?Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName,
        Operand $arg
    ): bool {
        if ($this->callArgUnpack($arg)) {
            return false;
        }

        return null !== $cfgCallOp
            && $this->shouldUseArrayProducerCallArgResolution($cfgCallOp, $argIndex, $calleeName)
            && $this->callArgIsDeadInlineTemporary($arg);
    }

}
