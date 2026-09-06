<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline Array_ call-arg producer discovery (#36387 / prior #36147).
 *
 * Extracted from {@see FindInlineCallArgProducerSlot} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see findInlineArrayProducerForCallArg}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait FindInlineArrayProducerForCallArg
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
}
