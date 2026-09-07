<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Single-producer and unequal producer/arg-count inline call-arg match
 * (`1 === $producerCount`, `$argCount > $producerCount`, positional
 * `$argIndex < $producerCount` remainder) (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Lives after `$producerCount === $argCount` equal-count paths
 * (and after chained-dim / union / nested-New via
 * {@see InlineCallArgChainedDimUnionNewAndExtraProducers}). Mirrors php-src
 * Zend/zend_compile.c call-arg operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgSingleAndUnequalCountProducers
{
    /**
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function tryMatchInlineCallArgSingleAndUnequalCountProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $callArg = $callArgs[$argIndex] ?? null;
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $producerCount = count($producers);
        $argCount = count($callArgs);
        if (1 === $producerCount) {
            // preg_replace_callback_array(['/pat/' => fn(...)], $subj) — pattern map is arg #0, not hoisted closure (#9072).
            if (
                ($producers[0] instanceof Op\Expr\ArrowFunction || $producers[0] instanceof Op\Expr\Closure)
                && 0 === $argIndex
                && 'preg_replace_callback_array' === $inlineFuncName
            ) {
                return null;
            }
            // preg_replace_callback($pat, fn(...), $subj) / iterator_apply($it, fn(...)) — lone hoisted closure maps to arg 1 (#12755, #15182).
            if (
                ($producers[0] instanceof Op\Expr\ArrowFunction || $producers[0] instanceof Op\Expr\Closure)
                && $argCount >= 2
                && \in_array($inlineFuncName, ['preg_replace_callback', 'iterator_apply'], true)
            ) {
                if (1 === $argIndex) {
                    return $producers[0];
                }

                return null;
            }
            if (
                ($producers[0] instanceof Op\Expr\ConstFetch || $producers[0] instanceof Op\Expr\ClassConstFetch)
                && $argCount - 1 === $argIndex
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArg)
                ) {
                    return $producers[0];
                }
                if ($producers[0] instanceof Op\Expr\ClassConstFetch) {
                    $pseudoName = $this->staticNameFromOperand($producers[0]->name);
                    if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                        return $producers[0];
                    }
                    // tempnam(sys_get_temp_dir(), E::A) — trailing enum case fetch (#10303).
                    if (null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg)) {
                        return $producers[0];
                    }
                }
                // Fall through — php-cfg dead call-arg temp (#9140, #9260, #9324).
            }
            if (
                $argCount - 1 === $argIndex
                && $producers[0] instanceof Op\Expr\Array_
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArg)
                ) {
                    return $producers[0];
                }
                // Fall through — dead haystack temp (#9888).
            }
            if ($argCount - 1 === $argIndex) {
                // array_column([['n'=>'a']], 'n') — haystack Array_ must not feed column_key (#13703).
                if (
                    $this->isEmbeddedCallLiteralArg($callArgs[0] ?? null)
                    && !($producers[0] instanceof Op\Expr\Array_)
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArgs[$argIndex] ?? null)
                ) {
                    return $producers[0];
                }
                // strtotime('next Monday', strtotime('...')) — nested FuncCall feeds trailing arg (#10838).
                if (
                    ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && null !== ($callArgs[0] ?? null)
                    && !$this->operandsReferToSameVariable($producers[0]->result, $callArgs[0])
                    && $this->operandsReferToSameVariable($producers[0]->result, $callArgs[$argIndex] ?? null)
                ) {
                    return $producers[0];
                }
            }
            if ($producers[0] instanceof Op\Expr\Array_) {
                if (1 === $argCount) {
                    return $producers[0];
                }
                $callArg = $callArgs[$argIndex] ?? null;
                if (null === $callArg) {
                    return null;
                }
                if ($this->operandsReferToSameVariable($producers[0]->result, $callArg)) {
                    return $producers[0];
                }
                // Fall through — inline haystack may use a dead temp (#9888).
            }
            if (
                ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
                && $argCount >= 2
            ) {
                // ftruncate($fp, -1) — hoisted UnaryMinus is the trailing arg, not arg #0 (#12622, #13450).
                if ($argCount - 1 === $argIndex) {
                    return $producers[0];
                }

                return null;
            }
            if (
                0 === $argIndex
                && !($producers[0] instanceof Op\Expr\Array_)
                && !($producers[0] instanceof Op\Expr\ConstFetch)
                && !($producers[0] instanceof Op\Expr\ClassConstFetch)
                && !($producers[0] instanceof Op\Expr\ArrowFunction)
                && !($producers[0] instanceof Op\Expr\Closure)
                && !($producers[0] instanceof Op\Expr\FirstClassCallable)
                && !($producers[0] instanceof Op\Expr\UnaryMinus)
                && !($producers[0] instanceof Op\Expr\UnaryPlus)
                && !$this->isComparisonInlineCallArgProducer($producers[0])
                && !$this->isEmbeddedCallLiteralArg($callArgs[0] ?? null)
                && !(
                    'array_column' === $inlineFuncName
                    && $producers[0] instanceof Op\Expr\Cast
                )
            ) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && !$this->namedCallArgMayUseFuncCallProducerResult($producers[0], $callArg)
                ) {
                    return null;
                }
                if (
                    null !== $callArg
                    && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && $this->funcCallExprByRefArgMatchesOperand($producers[0], $callArg)
                ) {
                    return null;
                }

                return $producers[0];
            }
            $closureMatch = $this->matchSingleClosureInlineProducer(
                $producers[0],
                $callArgs,
                $argIndex,
                $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName)
            );
            if (null !== $closureMatch) {
                return $closureMatch;
            }

            if ($argCount > $producerCount) {
                return $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                    $producers,
                    $callArgs,
                    $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
            }

            return null;
        }
        if ($argCount > $producerCount) {
            if (0 === $argIndex) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, ] = $nestedTrailing;

                    return $arrayChain[\count($arrayChain) - 1];
                }
            }

            return $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                $producers,
                $callArgs,
                $argIndex,
                $cfgCallOp,
                $block,
                $calleeName
            );
        }
        if ($argIndex < $producerCount) {
            if (0 === $argIndex) {
                if (null !== $cfgCallOp && null !== $block && null !== $block->orig) {
                    foreach ($producers as $producer) {
                        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                            continue;
                        }
                        $producerIndex = null;
                        foreach ($block->orig->children as $pi => $child) {
                            if ($child === $producer) {
                                $producerIndex = $pi;
                                break;
                            }
                        }
                        if (null === $producerIndex) {
                            continue;
                        }
                        $consumerIndex = null;
                        foreach ($block->orig->children as $ci => $child) {
                            if ($child === $cfgCallOp) {
                                $consumerIndex = $ci;
                                break;
                            }
                        }
                        if (null === $consumerIndex) {
                            continue;
                        }
                        if ($this->isNestedCallArgProducerForConsumer(
                            $producer,
                            $cfgCallOp,
                            $producerIndex,
                            $consumerIndex,
                            $block->orig->children
                        )) {
                            return $producer;
                        }
                    }
                }
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, ] = $nestedTrailing;

                    return $arrayChain[\count($arrayChain) - 1];
                }
                // Truly nested [[...]] is one call arg — outer Array_ is the producer (#9305, #10042).
                // Sibling Array_ producers (array_reduce([...], [$this,'m'], 0)) must not collapse to
                // the trailing Array_ (#25766); fall through to positional $paired mapping.
                if (
                    $this->producersAreNestedArrayLiteralChain($producers)
                    && $this->arrayProducersFormNestedChain($producers)
                ) {
                    return $producers[$producerCount - 1];
                }
                $lastArray = null;
                $arrayProducerCount = 0;
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Array_) {
                        ++$arrayProducerCount;
                        $lastArray = $producer;
                    }
                }
                // array_merge([1], [2]) — one hoisted Array_ per arg; do not wire arg #0 to trailing (#10093, #15552).
                // Also skip when sibling Array_ count differs from arity (embedded literal initial, #25766).
                if (
                    null !== $lastArray
                    && !(
                        $arrayProducerCount >= 2
                        && (
                            $argCount === $producerCount
                            || !$this->arrayProducersFormNestedChain(
                                array_values(array_filter(
                                    $producers,
                                    static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                                ))
                            )
                        )
                    )
                ) {
                    $callArg = $callArgs[$argIndex] ?? null;
                    if (
                        null !== $callArg
                        && (
                            $this->callArgOperandExpectsArrayProducer($callArg)
                            || $this->operandsReferToSameVariable($lastArray->result, $callArg)
                        )
                    ) {
                        return $lastArray;
                    }
                }
            }
            // Embedded literal args must not consume hoisted Array_ slots (#12008, http_build_query).
            if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
                return null;
            }

            $paired = $producers[$argIndex] ?? null;
            if (
                $this->isComparisonInlineCallArgProducer($paired)
                && (
                    null === ($callArgs[$argIndex] ?? null)
                    || !$this->operandsReferToSameVariable($paired->result, $callArgs[$argIndex])
                )
            ) {
                return null;
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

            // array_filter(str_split(...), is_numeric(...)) — FCC + nested haystack, not positional (#15490, #15961).
            if ($producerCount > $argCount) {
                $embeddedMapped = $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                    $producers,
                    $callArgs,
                    $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
                if (null !== $embeddedMapped) {
                    return $embeddedMapped;
                }
            }
            if (
                \in_array($inlineFuncName, ['in_array', 'array_search'], true)
                && \count($callArgs) >= 3
                && $this->isEmbeddedCallLiteralArg($callArgs[0] ?? null)
                && 2 === $producerCount
            ) {
                $constFuncSplit = $this->splitLeadingConstFetchWithFuncCallCallArg($producers);
                if (null !== $constFuncSplit) {
                    [$constFetch, $funcProducer] = $constFuncSplit;
                    if (1 === $argIndex) {
                        return $funcProducer;
                    }
                    if (2 === $argIndex) {
                        return $constFetch;
                    }
                }
                if (
                    ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                    && $producers[1] instanceof Op\Expr\ConstFetch
                ) {
                    if (1 === $argIndex) {
                        return $producers[0];
                    }
                    if (2 === $argIndex) {
                        return $producers[1];
                    }
                }
            }

            return $paired;
        }

    }
}
