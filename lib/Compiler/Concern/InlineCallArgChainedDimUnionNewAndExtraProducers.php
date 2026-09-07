<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Chained ArrayDimFetch / array-union Plus / nested-New ctor / direct-result /
 * boolean / concat / arithmetic / New_ / and `$argCount < $producerCount`
 * inline call-arg producer match (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Lives after merge/preg/nested/combine and #9456 hoisted-Assign
 * paths ({@see InlineCallArgMergeFamilyAndHoistedAssignProducers}). Tri-state return
 * preserves definitive null exits inside the extra-producer block. Mirrors php-src
 * Zend/zend_compile.c call-arg operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgChainedDimUnionNewAndExtraProducers
{
    /**
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     * @return Op\Expr|false|null matched producer; false = stop with null; null = continue
     */
    private function tryMatchInlineCallArgChainedDimUnionNewAndExtraProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): Op\Expr|false|null {
        $callArg = $callArgs[$argIndex] ?? null;
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $producerCount = count($producers);
        $argCount = count($callArgs);
        if (null !== $callArg) {
            $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer($producers, $argIndex);
            if (null !== $chainedDimFetch) {
                return $chainedDimFetch;
            }
            $arrayUnionPlus = $this->matchArrayUnionPlusInlineCallArgProducer(
                $producers,
                $callArg,
                $argCount
            );
            if (null !== $arrayUnionPlus) {
                return $arrayUnionPlus;
            }
            // new LimitIterator(new ArrayIterator([...]), …) — Array_ is inner-ctor prelude (#12916).
            $nestedCtorNew = $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, $callArgs);
            if (null !== $nestedCtorNew) {
                return $nestedCtorNew;
            }
            $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                $producers,
                $argIndex,
                $argCount,
                $producerCount
            );
            if (null !== $outerArray) {
                return $outerArray;
            }
            $directProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
            if (null !== $directProducer) {
                return $directProducer;
            }
            $booleanProducer = $this->matchBooleanBinaryOpInlineCallArgProducer($producers, $callArg);
            if (null !== $booleanProducer) {
                return $booleanProducer;
            }
            $chainedConcat = $this->matchChainedConcatInlineCallArgProducer($producers, $callArgs, $argIndex);
            if (null !== $chainedConcat) {
                return $chainedConcat;
            }
            $chainedArithmetic = $this->matchChainedArithmeticInlineCallArgProducer($producers, $callArgs, $argIndex);
            if (null !== $chainedArithmetic) {
                return $chainedArithmetic;
            }
        }
        if ($this->callArgIsNewExpression($callArg)) {
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\New_) {
                    return $producer;
                }
            }

            return false;
        }
        // new LimitIterator(new ArrayIterator([...]), …) — inner-ctor Array_ prelude + inline New_ feeds outer arg #0 (#12916).
        $nestedCtorNew = $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, $callArgs);
        if (null !== $nestedCtorNew) {
            return $nestedCtorNew;
        }
        if ($argCount < $producerCount) {
            $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer($producers, $argIndex);
            if (null !== $chainedDimFetch) {
                return $chainedDimFetch;
            }
            // array_fill_keys([[[1]]], 1) — all Array_ preludes belong to the sole hoisted arg (#10848).
            if (
                $this->producersAreNestedArrayLiteralChain($producers)
                && $this->arrayProducersFormNestedChain($producers)
            ) {
                $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
                if (null !== $soleHoisted && $argIndex === $soleHoisted) {
                    return $producers[$producerCount - 1];
                }
            }
            $nestedArrayTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
            if (null !== $nestedArrayTrailing) {
                [$arrayChain, $trailing] = $nestedArrayTrailing;
                if (1 + \count($trailing) === $argCount) {
                    if (0 === $argIndex) {
                        return $arrayChain[\count($arrayChain) - 1];
                    }

                    return $trailing[$argIndex - 1] ?? false;
                }
            }
            $leadingNestedRemaining = $this->splitLeadingNestedArrayLiteralChainWithRemainingProducers($producers);
            if (null !== $leadingNestedRemaining) {
                [$prefixChain, $remaining] = $leadingNestedRemaining;
                // iterator_to_array(new ArrayObject([...]), false) — lone Array_ is ctor prelude, not arg #0 (#11321, #12325).
                $inlineNewWithCtorArrayPrelude = 1 === \count($prefixChain)
                    && ($prefixChain[0] ?? null) instanceof Op\Expr\Array_
                    && ($remaining[0] ?? null) instanceof Op\Expr\New_;
                if (!$inlineNewWithCtorArrayPrelude) {
                    $trailingArgCount = $this->countInlineCallArgProducersInRemaining($remaining);
                    if (1 + $trailingArgCount === $argCount) {
                        if (0 === $argIndex) {
                            return $prefixChain[\count($prefixChain) - 1];
                        }

                        return $this->inlineCallArgProducerAtRemainingIndex($remaining, $argIndex - 1);
                    }
                }
            }
            // php-cfg hoists compare/array-dim preludes before trailing literal args (#5901, #9660).
            if ($argIndex < $argCount - 1) {
                $trailingForLaterArgs = $argCount - 1 - $argIndex;
                $prefixEnd = $producerCount - $trailingForLaterArgs;
                if ($prefixEnd > 0) {
                    $prefixLast = $producers[$prefixEnd - 1] ?? null;
                    $callArg = $callArgs[$argIndex] ?? null;
                    if (
                        $this->isComparisonInlineCallArgProducer($prefixLast)
                        && null !== $callArg
                        && $this->operandsReferToSameVariable($prefixLast->result, $callArg)
                    ) {
                        return $prefixLast;
                    }
                    if (
                        $prefixLast instanceof Op\Expr\BinaryOp\Plus
                        || $prefixLast instanceof Op\Expr\BinaryOp\Concat
                    ) {
                        return $prefixLast;
                    }
                }
            }
            $extra = $producerCount - $argCount;
            $tail = array_slice($producers, -$extra);
            if (
                !$this->producersAreNestedArrayLiteralChain($tail)
                && !$this->producersAreChainedAssignChain($producers)
            ) {
                $filtered = $this->filterNestedNewInlineCallArgProducers($producers, $cfgCallOp);
                if (\count($filtered) === $argCount) {
                    $mapped = $filtered[$argIndex] ?? null;
                    if (
                        0 === $argIndex
                        && $mapped instanceof Op\Expr\Array_
                        && null !== ($callArgs[0] ?? null)
                        && !$this->callArgOperandExpectsArrayProducer($callArgs[0])
                        && (($filtered[1] ?? null) instanceof Op\Expr\FuncCall
                            || ($filtered[1] ?? null) instanceof Op\Expr\NsFuncCall
                            || ($filtered[1] ?? null) instanceof Op\Expr\StaticCall
                            || ($filtered[1] ?? null) instanceof Op\Expr\MethodCall
                            || ($filtered[1] ?? null) instanceof Op\Expr\Cast)
                    ) {
                        return $filtered[1];
                    }

                    return $mapped;
                }
                // PropertyFetch prelude for empty($obj->prop) / isset($obj->prop) call args (#8901).
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Empty_ || $producer instanceof Op\Expr\Isset_) {
                        if (1 === $argCount) {
                            return $producer;
                        }
                        $callArg = $callArgs[$argIndex] ?? null;
                        if (null !== $callArg && $this->operandsReferToSameVariable($producer->result, $callArg)) {
                            return $producer;
                        }
                    }
                }
                if (1 === $argCount) {
                    $last = $producers[$producerCount - 1] ?? null;
                    // PropertyFetch/StaticPropertyFetch prelude before ++/-- (#10123, zend_execute.c).
                    if ($last instanceof Op\Expr\PostInc
                        || $last instanceof Op\Expr\PreInc
                        || $last instanceof Op\Expr\PostDec
                        || $last instanceof Op\Expr\PreDec
                    ) {
                        return $last;
                    }
                    if ($last instanceof Op\Expr\NullsafePropertyFetch || $last instanceof Op\Expr\NullsafeMethodCall) {
                        return $last;
                    }
                    // Clone/assign prelude before property read (#9114, var_dump($c->n) in try).
                    if ($last instanceof Op\Expr\PropertyFetch || $last instanceof Op\Expr\ArrayDimFetch) {
                        return $last;
                    }
                    // (new C())->m() inline call-arg (#9428, zend_traits.c alias visibility repro).
                    if ($last instanceof Op\Expr\MethodCall || $last instanceof Op\Expr\StaticCall) {
                        return $last;
                    }
                    // Inline first-class callable call arg (#9769, zend_closures.c).
                    if ($last instanceof Op\Expr\FirstClassCallable) {
                        return $last;
                    }
                    // php-cfg dead temp for `var_dump(E::A::class)` — last producer is Case::class (#9426, #9518).
                    if ($last instanceof Op\Expr\ClassConstFetch) {
                        $pseudoName = $this->staticNameFromOperand($last->name);
                        if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                            return $last;
                        }
                    }
                    // Hoisted ConstFetch prelude before inline scalar cast (#10143, #9479).
                    if ($last instanceof Op\Expr\Cast) {
                        return $last;
                    }
                    // id(clone new C()) — Clone_ after New_ prelude (#13687).
                    if ($last instanceof Op\Expr\Clone_) {
                        return $last;
                    }
                    // Inline array union `var_export([...] + [...])` — Plus after Array_ preludes (#10490, #10578).
                    if ($last instanceof Op\Expr\BinaryOp\Plus) {
                        return $last;
                    }
                    // Hoisted ConstFetch prelude before inline concat call arg (#10663, zend_operators.c).
                    if ($last instanceof Op\Expr\BinaryOp\Concat) {
                        return $last;
                    }
                    // var_dump($x !== false) — comparison is the sole hoisted arg (#13694, zend_compile.c).
                    if ($this->isComparisonInlineCallArgProducer($last)) {
                        return $last;
                    }
                    // Inline eval() call arg — php-cfg dead temp vs TYPE_EVAL producer (#10661, zif_eval).
                    if ($last instanceof Op\Expr\Eval_) {
                        return $last;
                    }
                    // is_countable(new ArrayIterator([])) — ctor Array_ prelude + inline New_ (#10900).
                    if ($last instanceof Op\Expr\New_) {
                        return $last;
                    }
                    if ($last instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                    ) {
                        if (
                            null !== ($callArgs[0] ?? null)
                            && $this->callArgOperandExpectsArrayProducer($callArgs[0])
                        ) {
                            foreach (array_reverse($producers) as $producer) {
                                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                                    return $producer;
                                }
                            }
                        }

                        return $last;
                    }
                    // var_export([true, $dt->format('Y-m-d')]) — trailing Array_ is arg #0, not hoisted element call (#10733, #16067).
                    if (
                        $last instanceof Op\Expr\Array_
                        && null !== ($callArgs[0] ?? null)
                        && $this->callArgIsDeadInlineTemporary($callArgs[0])
                        && $this->callArgOperandExpectsArrayProducer($callArgs[0])
                    ) {
                        return $last;
                    }
                }

                $last = $producers[$producerCount - 1] ?? null;
                if ($last instanceof Op\Expr\Assign) {
                    $last = $last->expr;
                }
                if ($last instanceof Op\Expr\BinaryOp\BitwiseOr
                    || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                    || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                ) {
                    $nonEmbeddedArgIndices = [];
                    foreach ($callArgs as $i => $arg) {
                        if (null !== $arg && !$this->isEmbeddedCallLiteralArg($arg)) {
                            $nonEmbeddedArgIndices[] = $i;
                        }
                    }
                    $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                    if ($argIndex === $trailingNonEmbedded) {
                        return $last;
                    }
                }

                // iterator_to_array(new ArrayObject([...]), false) — ctor Array_ prelude + New_ + trailing arg (#11321).
                if (
                    $extra >= 1
                    && ($producers[0] ?? null) instanceof Op\Expr\Array_
                    && ($producers[1] ?? null) instanceof Op\Expr\New_
                ) {
                    $mappedIndex = $argIndex + 1;
                    if ($mappedIndex >= 0 && $mappedIndex < $producerCount) {
                        return $producers[$mappedIndex];
                    }
                }

                return false;
            }
            // array_combine(array_keys(...), [...]) — inner Array_ prelude + FuncCall + trailing Array_ (#15558, #15857).
            if ('array_combine' === $inlineFuncName && 2 === $argCount) {
                $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
                if (null !== $arrayCombinePair) {
                    return $arrayCombinePair;
                }
            }
            // php-cfg emits inner-then-outer Array_ per inline arg (#4738, #10196, #10662).
            if ($this->producersAreNestedArrayLiteralChain($producers) && 0 === $producerCount % $argCount) {
                $depth = intdiv($producerCount, $argCount);
                $mappedIndex = $argIndex * $depth + ($depth - 1);
            } elseif (
                $extra >= 1
                && ($producers[0] ?? null) instanceof Op\Expr\Array_
                && ($producers[1] ?? null) instanceof Op\Expr\New_
            ) {
                $mappedIndex = $argIndex + 1;
            } elseif (1 === $argCount) {
                $mappedIndex = $producerCount - 1;
            } else {
                $mappedIndex = $argIndex + ($argIndex > 0 ? $extra : 0);
                // current([1,2]) / key([...]) / C::__set_state([]) hoisted before var_export(..., true) — arg #0 is call result, not Array_ (#10654, #11896).
                if (
                    0 === $argIndex
                    && ($producers[0] ?? null) instanceof Op\Expr\Array_
                    && (($producers[1] ?? null) instanceof Op\Expr\FuncCall
                        || ($producers[1] ?? null) instanceof Op\Expr\NsFuncCall
                        || ($producers[1] ?? null) instanceof Op\Expr\StaticCall
                        || ($producers[1] ?? null) instanceof Op\Expr\MethodCall)
                    && null !== ($callArgs[0] ?? null)
                    && !$this->callArgOperandExpectsArrayProducer($callArgs[0])
                ) {
                    $mappedIndex = 1;
                }
            }
            if ($mappedIndex >= $producerCount || $mappedIndex < 0) {
                return false;
            }

            $mapped = $producers[$mappedIndex] ?? null;
            if (
                $this->isComparisonInlineCallArgProducer($mapped)
                && (
                    null === ($callArgs[$argIndex] ?? null)
                    || !$this->operandsReferToSameVariable($mapped->result, $callArgs[$argIndex])
                )
            ) {
                foreach ($producers as $candidate) {
                    if (
                        $candidate instanceof Op\Expr\ConstFetch
                        && null !== ($callArgs[$argIndex] ?? null)
                        && $this->operandsReferToSameVariable($candidate->result, $callArgs[$argIndex])
                    ) {
                        return $candidate;
                    }
                }

                return false;
            }

            return $mapped;
        }

        return null;
    }
}
