<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * array_column / tempnam-enum / ConstFetch+FuncCall / mbstring / array_map callback
 * and trailing haystack inline call-arg producer match (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Positive-match only — null means continue later heuristics
 * (empty-producer exit stays in the hub). Mirrors php-src Zend/zend_compile.c
 * call-arg operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgArrayColumnMbstringAndCallbackProducers
{
    /**
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     * @return Op\Expr|null matched producer; null to continue later heuristics
     */
    private function tryMatchInlineCallArgArrayColumnMbstringAndCallbackProducer(
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
        // array_column([(object)[...], ...], 'col') — outer haystack Array_, not (object) Cast preludes (#11236).
        if (
            'array_column' === $inlineFuncName
            && 0 === $argIndex
            && null !== $block
            && null !== $cfgCallOp
        ) {
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                return $immediate;
            }
            $arrayTail = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if ([] !== $arrayTail) {
                return $arrayTail[\count($arrayTail) - 1];
            }
        }
        // array_column([[..]], null, 'x') — nested haystack Array_ chain + trailing null ConstFetch (#15914).
        if ('array_column' === $inlineFuncName) {
            $mappedColumn = $this->matchArrayColumnNestedHaystackTrailingProducers(
                $producers,
                $callArgs,
                $argIndex,
                $cfgCallOp
            );
            if (null !== $mappedColumn) {
                return $mappedColumn;
            }
        }
        // tempnam(sys_get_temp_dir(), E::CASE) — nested FuncCall + trailing enum ClassConstFetch (#10303).
        if (2 === $argCount && 1 === $producerCount && 1 === $argIndex) {
            $sole = $producers[0] ?? null;
            $callArg = $callArgs[$argIndex] ?? null;
            if (
                $sole instanceof Op\Expr\ClassConstFetch
                && null !== $callArg
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                return $sole;
            }
        }
        if (2 === $argCount && $producerCount >= 2) {
            $funcProducer = null;
            $enumFetch = null;
            $constFetch = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                    || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                    $enumFetch = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constFetch = $producer;
                }
            }
            if (null !== $funcProducer && null !== $enumFetch) {
                return (0 === $argIndex) ? $funcProducer : $enumFetch;
            }
            $arrayProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducer = $producer;
                    break;
                }
            }
            if (
                'preg_replace_callback_array' === $inlineFuncName
                && null !== $arrayProducer
                && null !== $enumFetch
            ) {
                return 0 === $argIndex ? $arrayProducer : $enumFetch;
            }
            // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch + sibling FuncCall (#15833).
            if (null !== $funcProducer && null !== $constFetch) {
                $skipClosureConstFuncOrdinal = false;
                if ($this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName) >= 0) {
                    foreach ($producers as $closureCandidate) {
                        if ($closureCandidate instanceof Op\Expr\ArrowFunction
                            || $closureCandidate instanceof Op\Expr\Closure
                            || $closureCandidate instanceof Op\Expr\FirstClassCallable) {
                            // array_map(intval(...), str_split(str_repeat(...))) — ConstFetch feeds nested haystack, not callback (#16279).
                            $skipClosureConstFuncOrdinal = true;
                            break;
                        }
                    }
                }
                if (!$skipClosureConstFuncOrdinal) {
                    $callArg = $callArgs[$argIndex] ?? null;
                    if (null !== $callArg) {
                        if ($this->operandsReferToSameVariable($constFetch->result, $callArg)) {
                            return $constFetch;
                        }
                        if ($this->operandsReferToSameVariable($funcProducer->result, $callArg)) {
                            return $funcProducer;
                        }
                    }
                    $nonEmbeddedArgIndices = [];
                    foreach ($callArgs as $i => $candidate) {
                        if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                            $nonEmbeddedArgIndices[] = (int) $i;
                        }
                    }
                    $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
                    if (false !== $producerOrdinal) {
                        if (
                            null !== $cfgCallOp
                            && null !== $block
                            && null !== $block->orig
                            && 2 === $argCount
                        ) {
                            $consumerIndex = null;
                            $funcProducerIndex = null;
                            $constFetchIndex = null;
                            foreach ($block->orig->children as $i => $child) {
                                if ($child === $cfgCallOp) {
                                    $consumerIndex = $i;
                                }
                                if ($child === $funcProducer) {
                                    $funcProducerIndex = $i;
                                }
                                if ($child === $constFetch) {
                                    $constFetchIndex = $i;
                                }
                            }
                            if (
                                null !== $consumerIndex
                                && null !== $funcProducerIndex
                                && $this->isNestedCallArgProducerForConsumer(
                                    $funcProducer,
                                    $cfgCallOp,
                                    $funcProducerIndex,
                                    $consumerIndex,
                                    $block->orig->children
                                )
                            ) {
                                // var_export(f(), true) / array_keys($a, null) — nested result is arg0 (#11272, #10373).
                                return 0 === $argIndex ? $funcProducer : $constFetch;
                            }
                            if (
                                null !== $funcProducerIndex
                                && null !== $constFetchIndex
                                && $funcProducerIndex !== $constFetchIndex
                            ) {
                                // Sibling call + hoisted true/false/null — wire by CFG order (#10778, #15833).
                                $earlierIsFunc = $funcProducerIndex < $constFetchIndex;

                                return (0 === $producerOrdinal) === $earlierIsFunc ? $funcProducer : $constFetch;
                            }
                        }

                        if (1 === \count($nonEmbeddedArgIndices)) {
                            return $funcProducer;
                        }
                        if (
                            'var_export' === $inlineFuncName
                            && null !== $funcProducer
                            && null !== $constFetch
                            && ($funcProducer instanceof Op\Expr\MethodCall || $funcProducer instanceof Op\Expr\StaticCall)
                        ) {
                            return 0 === $producerOrdinal ? $funcProducer : $constFetch;
                        }

                        return 0 === $producerOrdinal ? $constFetch : $funcProducer;
                    }
                }
            }
        }
        $mappedArraySplice = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            $argCount,
            $inlineFuncName
        );
        if (null !== $mappedArraySplice) {
            return $mappedArraySplice;
        }
        $mappedMbstring = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            $argCount,
            $inlineFuncName
        );
        if (null !== $mappedMbstring) {
            return $mappedMbstring;
        }
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName);
        if (
            $callbackArgIndex >= 0
            && $argIndex === $callbackArgIndex
            && 2 === $argCount
            && null !== $block
        ) {
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if ($leadingCallback instanceof Op\Expr\ArrowFunction
                || $leadingCallback instanceof Op\Expr\Closure
                || $leadingCallback instanceof Op\Expr\FirstClassCallable) {
                return $leadingCallback;
            }
        }
        // array_map(null, [[..], ..]) — ConstFetch callback + nested inline Array_ preludes (#9143, #16225).
        if (
            'array_map' === $inlineFuncName
            && $argCount >= 2
            && null !== $block
            && null !== $cfgCallOp
        ) {
            $nullCallback = $this->arrayMapNullCallbackProducerBeforeCfgCall($cfgCallOp, $block);
            if ($nullCallback instanceof Op\Expr\ConstFetch) {
                if (0 === $argIndex) {
                    return $nullCallback;
                }
                $nullHaystack = $this->arrayMapInlineNullHaystackProducerForArgIndex($cfgCallOp, $block, $argIndex);
                if ($nullHaystack instanceof Op\Expr\ConstFetch) {
                    return $nullHaystack;
                }
                if (2 === $argCount && 1 === $argIndex) {
                    $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    if ($immediate instanceof Op\Expr\Array_) {
                        return $immediate;
                    }
                    $arrayTail = array_values(array_filter(
                        $producers,
                        static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                    ));
                    if ([] !== $arrayTail) {
                        return $arrayTail[\count($arrayTail) - 1];
                    }
                }
            }
        }
        // array_map($cb, $a, $b, …) — zip hoisted Array_ producers before inlineHoisted slot walk (#4539, #9143).
        if (
            'array_map' === $inlineFuncName
            && $argCount >= 3
            && $argIndex >= 1
            && null !== $block
            && null !== $cfgCallOp
            && null === $this->arrayMapInlineNullHaystackProducerForArgIndex($cfgCallOp, $block, $argIndex)
        ) {
            $mapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $mapped) {
                return $mapped;
            }
        }
        if (
            1 === $callbackArgIndex
            && 0 === $argIndex
            && 2 === $argCount
            && null !== $block
        ) {
            $haystackProducer = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
            if ($haystackProducer instanceof Op\Expr\FuncCall
                || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                return $haystackProducer;
            }
        }

        return null;
    }
}
