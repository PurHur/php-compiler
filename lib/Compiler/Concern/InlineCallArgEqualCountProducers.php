<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Equal producer/arg-count inline call-arg producer match (#36387 / #36403).
 *
 * Covers bitwise-or/and/xor trailing, dim+concat / const+bitwise pairs,
 * array_merge/array_combine pairs, var_export sibling, filter_var ConstFetch
 * nests, sibling FuncCall dead temps, Closure/FCC + Array_ feeds, and related
 * `$producerCount === $argCount` paths. Extracted from
 * {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a smaller
 * Concern TU. Lives after chained-dim / union / nested-New
 * ({@see InlineCallArgChainedDimUnionNewAndExtraProducers}). Tri-state return
 * preserves definitive null exits inside the equal-count block. Mirrors php-src
 * Zend/zend_compile.c call-arg operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgEqualCountProducers
{
    /**
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     * @return Op\Expr|false|null matched producer; false = stop with null; null = continue
     */
    private function tryMatchInlineCallArgEqualCountProducer(
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
        if ($producerCount !== $argCount) {
            return null;
        }
        $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer($producers, $argIndex);
        if (null !== $chainedDimFetch) {
            return $chainedDimFetch;
        }
        $last = $producers[$producerCount - 1] ?? null;
        if ($last instanceof Op\Expr\BinaryOp\BitwiseOr
            || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $last instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $nonEmbeddedArgIndices = [];
            foreach ($callArgs as $i => $candidateArg) {
                if (null !== $candidateArg && !$this->isEmbeddedCallLiteralArg($candidateArg)) {
                    $nonEmbeddedArgIndices[] = $i;
                }
            }
            $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $trailingNonEmbedded) {
                return $last;
            }
        }
        // str_contains($arr['k'], $fn . '():') — hoisted dim-fetch + concat (#13662, zend_execute.c).
        if (2 === $producerCount) {
            $dimIdx = null;
            $concatIdx = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ArrayDimFetch) {
                    $dimIdx = $pi;
                } elseif ($producer instanceof Op\Expr\BinaryOp\Concat) {
                    $concatIdx = $pi;
                }
            }
            if (null !== $dimIdx && null !== $concatIdx) {
                return (0 === $argIndex) ? $producers[$dimIdx] : $producers[$concatIdx];
            }
            $constIdx = null;
            $bitwiseIdx = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $constIdx = $pi;
                } elseif ($producer instanceof Op\Expr\BinaryOp\BitwiseOr
                    || $producer instanceof Op\Expr\BinaryOp\BitwiseAnd
                    || $producer instanceof Op\Expr\BinaryOp\BitwiseXor
                ) {
                    $bitwiseIdx = $pi;
                }
            }
            if (null !== $constIdx && null !== $bitwiseIdx) {
                return (0 === $argIndex) ? $producers[$constIdx] : $producers[$bitwiseIdx];
            }
        }
        // array_merge(array_keys($src), ['b']) / array_merge(['a'=>1], array_keys(...)) (#12450, #13704, #13760).
        if (\in_array($inlineFuncName, ['array_merge', 'array_merge_recursive'], true)) {
            $arrayMergePair = $this->matchArrayMergeFuncCallAndArrayInlineProducers($producers, $argIndex);
            if (null !== $arrayMergePair) {
                return $arrayMergePair;
            }
        }
        // array_combine(array_keys(...), [...]) / array_combine([...], [...]) (#13776, #10214).
        if ('array_combine' === $inlineFuncName && 2 === $producerCount && 2 === $argCount) {
            $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
            if (null !== $arrayCombinePair) {
                return $arrayCombinePair;
            }
        }
        // var_export(C::__set_state([]), true) — arg #0 is sibling call result, nested Array_ is callee arg (#11896).
        // var_export(require_once $f, true) — Include_/Eval_ is arg #0 (#25852).
        if ('var_export' === $inlineFuncName && 2 === $producerCount && 2 === $argCount && 0 === $argIndex) {
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\StaticCall
                    || $producer instanceof Op\Expr\MethodCall
                    || $producer instanceof Op\Expr\FuncCall
                    || $producer instanceof Op\Expr\NsFuncCall
                    || $producer instanceof Op\Expr\Include_
                    || $producer instanceof Op\Expr\Eval_) {
                    return $producer;
                }
            }
        }
        // filter_var('x', FILTER_*, ['options' => ['regexp' => '/a/']]) — ConstFetch + nested Array_ (#12007).
        $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($producers);
        if (null !== $leadingConstNested) {
            [$constFetch, $arrayChain] = $leadingConstNested;
            /** @var list<Op\Expr\ConstFetch> $leadingConsts */
            $leadingConsts = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $leadingConsts[] = $producer;
                    continue;
                }
                if ($producer instanceof Op\Expr\Array_) {
                    break;
                }
            }
            $arrayArgIndex = $argCount - 1;
            if ($argIndex === $arrayArgIndex) {
                return $arrayChain[\count($arrayChain) - 1];
            }
            $constArgIndex = null;
            for ($i = $arrayArgIndex - 1; $i >= 0; --$i) {
                if (!$this->isEmbeddedCallLiteralArg($callArgs[$i] ?? null)) {
                    $constArgIndex = $i;
                    break;
                }
            }
            if ($argIndex === $constArgIndex) {
                return $constFetch;
            }
            if (isset($leadingConsts[$argIndex])) {
                return $leadingConsts[$argIndex];
            }

            return null;
        }
        // filter_var('x', FILTER_*, ['flags' => FILTER_*]) — ConstFetch + element ConstFetch + Array_ (#12326).
        $leadingConstArray = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
        if (null !== $leadingConstArray) {
            [$constFetch, $array] = $leadingConstArray;
            /** @var list<Op\Expr\ConstFetch> $leadingConsts */
            $leadingConsts = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $leadingConsts[] = $producer;
                    continue;
                }
                if ($producer instanceof Op\Expr\Array_) {
                    break;
                }
            }
            $arrayArgIndex = $argCount - 1;
            if ($argIndex === $arrayArgIndex) {
                return $array;
            }
            $constArgIndex = null;
            for ($i = $arrayArgIndex - 1; $i >= 0; --$i) {
                if (!$this->isEmbeddedCallLiteralArg($callArgs[$i] ?? null)) {
                    $constArgIndex = $i;
                    break;
                }
            }
            if ($argIndex === $constArgIndex) {
                return $constFetch;
            }
            if (isset($leadingConsts[$argIndex])) {
                return $leadingConsts[$argIndex];
            }

            return null;
        }
        // php-cfg `f(g(), h())` hoists sibling FuncCall producers with dead arg temps (#9463, #10917).
        if ($argIndex < $producerCount) {
            $allSiblingFuncCalls = true;
            foreach ($producers as $candidate) {
                if (
                    !$candidate instanceof Op\Expr\FuncCall
                    && !$candidate instanceof Op\Expr\NsFuncCall
                ) {
                    $allSiblingFuncCalls = false;
                    break;
                }
            }
            if ($allSiblingFuncCalls) {
                foreach ($producers as $candidate) {
                    if ($candidate instanceof Op\Expr\ArrowFunction
                        || $candidate instanceof Op\Expr\Closure
                        || $candidate instanceof Op\Expr\FirstClassCallable) {
                        $allSiblingFuncCalls = false;
                        break;
                    }
                }
            }
            if ($allSiblingFuncCalls) {
                // f(g(), h()) only — unrelated preceding stmt FuncCalls must not feed named locals (#11187).
                if (!$this->callArgsAreDistinctInlineTemporaries($callArgs)) {
                    return null;
                }

                return $producers[$argIndex];
            }
        }
        $closureIdx = null;
        $arrayIdx = null;
        foreach ($producers as $pi => $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                $closureIdx = $pi;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $arrayIdx = $pi;
            }
        }
        // Closure/FCC + inline Array_ — match by dead-temp operand wiring first (#10827, array_all/any/find);
        // array_map(callback, array) fallback when links are opaque (#10651, #11450).
        // array_all/any/find(null, fn) — hoisted null ConstFetch + Closure (#12766).
        if (
            null !== $closureIdx
            && null === $arrayIdx
            && 2 === $producerCount
            && 2 === $argCount
        ) {
            $constIdx = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $constIdx = $pi;
                    break;
                }
            }
            $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName);
            if (null !== $constIdx && $callbackArgIndex >= 0) {
                $constArgIndex = 1 - $callbackArgIndex;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureIdx];
                }
                if ($argIndex === $constArgIndex) {
                    return $producers[$constIdx];
                }

                return null;
            }
        }
        if (null !== $closureIdx && null !== $arrayIdx && 2 === $producerCount && 2 === $argCount) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (null !== $callArg) {
                if ($this->operandsReferToSameVariable($producers[$arrayIdx]->result, $callArg)) {
                    return $producers[$arrayIdx];
                }
                if ($this->operandsReferToSameVariable($producers[$closureIdx]->result, $callArg)) {
                    return $producers[$closureIdx];
                }
            }
            $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
                $inlineFuncName
            );
            if ($callbackArgIndex >= 0) {
                $arrayArgIndex = 1 - $callbackArgIndex;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureIdx];
                }
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayIdx];
                }

                return null;
            }
        }
        if ($this->producersAreNestedArrayLiteralChain($producers)) {
            // array_fill_keys([[1]], 1) — nested Array_ preludes map to the sole hoisted arg (#10848).
            if (
                $this->arrayProducersFormNestedChain($producers)
                && $producerCount >= 2
            ) {
                $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
                if (null !== $soleHoisted && $argIndex === $soleHoisted) {
                    return $producers[$producerCount - 1];
                }
            }
            $callArg = $callArgs[$argIndex] ?? null;
            $paired = $producers[$argIndex] ?? null;
            if (
                null !== $callArg
                && $paired instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($paired->result, $callArg)
            ) {
                return $paired;
            }
            // php-cfg dead call-arg temps for sibling inline Array_ producers (#8561, #10231).
            if ($paired instanceof Op\Expr\Array_) {
                if (
                    1 === $argCount
                    && $producerCount >= 2
                    && $this->arrayProducersFormNestedChain(array_values(array_filter(
                        $producers,
                        static fn (Op\Expr $p): bool => $p instanceof Op\Expr\Array_
                    )))
                ) {
                    $outer = $producers[$producerCount - 1];

                    return $outer instanceof Op\Expr\Array_ ? $outer : $paired;
                }

                return $paired;
            }
            if ($argIndex < $argCount - 1) {
                // in_array(null, [null]) — hoisted null needle must not lose to haystack Array_ (#10909).
                if (
                    $paired instanceof Op\Expr\ConstFetch
                    && $this->operandsReferToSameVariable($paired->result, $callArgs[$argIndex] ?? null)
                ) {
                    $name = $this->staticNameFromOperand($paired->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                        return $paired;
                    }
                }

                return null;
            }
            if ($producerCount > 1) {
                return $producers[$producerCount - 1];
            }
        }
        $paired = $producers[$argIndex] ?? null;
        if ($paired instanceof Op\Expr\Assign) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (
                null === $callArg
                || null === $paired->var
                || !$this->operandsReferToSameVariable($paired->var, $callArg)
            ) {
                return null;
            }
        }
        if ($paired instanceof Op\Expr\FuncCall || $paired instanceof Op\Expr\NsFuncCall) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (
                null !== $callArg
                && !$this->namedCallArgMayUseFuncCallProducerResult($paired, $callArg)
            ) {
                return null;
            }
            if (
                (null === $callArg || !$this->operandsReferToSameVariable($paired->result, $callArg))
                && $argCount > 1
                && !$this->callArgsAreDistinctInlineTemporaries($callArgs)
                && !$this->callArgIsDeadInlineTemporary($callArg)
            ) {
                return null;
            }
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            null !== $callArg
            && !$this->isEmbeddedCallLiteralArg($callArg)
        ) {
            foreach ($producers as $producer) {
                if (!$producer instanceof Op\Expr\BinaryOp\Coalesce) {
                    continue;
                }
                if (
                    $callArg instanceof Operand\Temporary
                    || $producer->result === $callArg
                    || $this->operandsReferToSameVariable($producer->result, $callArg)
                ) {
                    return $producer;
                }
            }
        }
        if (
            $paired instanceof Op\Expr\Array_
            && null !== $callArg
            && !$this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            foreach ($producers as $candidate) {
                if (
                    ($candidate instanceof Op\Expr\StaticCall
                        || $candidate instanceof Op\Expr\MethodCall
                        || $candidate instanceof Op\Expr\FuncCall
                        || $candidate instanceof Op\Expr\NsFuncCall)
                    && null !== $candidate->result
                    && $this->operandsReferToSameVariable($candidate->result, $callArg)
                ) {
                    return $candidate;
                }
            }
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

        return $paired;

        return null;
    }
}
