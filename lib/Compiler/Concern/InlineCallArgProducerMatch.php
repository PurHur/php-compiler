<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\JIT\OperandName;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Inline call-arg producer matching (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m). Follows
 * CompileCallArgSends: matchInlineCallArgProducer*. Early dead-temp /
 * sibling-New_ paths live in {@see InlineCallArgDeadTempAndSiblingNewProducers};
 * array_column / mbstring / array_map callback paths live in
 * {@see InlineCallArgArrayColumnMbstringAndCallbackProducers};
 * merge/preg/nested/combine + hoisted-Assign dead-temp paths live in
 * {@see InlineCallArgMergeFamilyAndHoistedAssignProducers};
 * chained-dim / union / nested-New / extra-producer-count paths live in
 * {@see InlineCallArgChainedDimUnionNewAndExtraProducers};
 * specialized matchers (array_splice / mbstring / filter / embedded literals)
 * live in {@see MatchInlineCallArgProducerWithEmbeddedLiterals}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgProducerMatch
{
    /**
     * Map a hoisted inline call-arg producer to the callee argument index (#8561, #5799).
     *
     * php-cfg may emit fewer preceding Expr_* producers than call args when literals stay
     * embedded in the FuncCall (e.g. array_fill_keys(array('a'), 'x')).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr
    {
        $callArg = $callArgs[$argIndex] ?? null;
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $earlyDeadTempOrSiblingNew = $this->tryMatchInlineCallArgDeadTempAndSiblingNewProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $earlyDeadTempOrSiblingNew) {
            return $earlyDeadTempOrSiblingNew;
        }
        $producerCount = count($producers);
        $argCount = count($callArgs);
        $columnMbstringOrCallback = $this->tryMatchInlineCallArgArrayColumnMbstringAndCallbackProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $columnMbstringOrCallback) {
            return $columnMbstringOrCallback;
        }
        if (0 === $producerCount) {
            return null;
        }
        $mergePregNestedOrCombine = $this->tryMatchInlineCallArgMergePregNestedAndCombineProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $mergePregNestedOrCombine) {
            return $mergePregNestedOrCombine;
        }
        if ($this->callIncludesNamedParameter($cfgCallOp)) {
            $callArg = $callArgs[$argIndex] ?? null;
            if (null === $callArg) {
                return null;
            }
            if (
                $this->callArgIsDeadInlineTemporary($callArg)
                && null !== $cfgCallOp
                && null !== $block
                && null !== $block->orig
            ) {
                $byIndex = $this->inlineHoistedProducerForCallArgIndex(
                    $cfgCallOp,
                    $argIndex,
                    $producers,
                    $block->orig->children,
                    $block
                );
                if (null !== $byIndex) {
                    $trailingUnaryProducer = $producers[$producerCount - 1] ?? null;
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && (
                            $trailingUnaryProducer instanceof Op\Expr\Cast
                            || $trailingUnaryProducer instanceof Op\Expr\Clone_
                            || $trailingUnaryProducer instanceof Op\Expr\New_
                        )
                    ) {
                        return $trailingUnaryProducer;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && null !== $callArg
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                            $producers,
                            $argIndex,
                            $argCount,
                            $producerCount
                        );
                        if (null !== $outerArray) {
                            return $outerArray;
                        }
                        $byIndex = null;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && $trailingUnaryProducer instanceof Op\Expr\BinaryOp\Plus
                    ) {
                        $byIndex = null;
                    }
                    if (
                        $byIndex instanceof Op\Expr\Array_
                        && $this->producersIncludeInlineArrayUnionPlus($producers)
                    ) {
                        $byIndex = null;
                    }
                    if (null !== $byIndex) {
                        return $byIndex;
                    }
                }
            }
            foreach ($producers as $producer) {
                if (
                    null !== $producer->result
                    && $this->operandsReferToSameVariable($producer->result, $callArg)
                ) {
                    if (
                        $producer instanceof Op\Expr\Array_
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        continue;
                    }

                    return $producer;
                }
            }

            return null;
        }
        $hoistedAssignDeadTemp = $this->tryMatchInlineCallArgHoistedAssignDeadTempProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (null !== $hoistedAssignDeadTemp) {
            return $hoistedAssignDeadTemp;
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            $embeddedCallArg = $callArgs[$argIndex] ?? null;
            if (
                $embeddedCallArg instanceof Operand
                && $this->callArgOperandExpectsArrayProducer($embeddedCallArg)
                && $argCount < $producerCount
            ) {
                $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
                if (null !== $nestedTrailing) {
                    [$arrayChain, $trailing] = $nestedTrailing;
                    if (1 + \count($trailing) === $argCount && 0 === $argIndex) {
                        $outer = $arrayChain[\count($arrayChain) - 1] ?? null;
                        if ($outer instanceof Op\Expr\Array_) {
                            return $outer;
                        }
                    }
                }
            }

            return null;
        }
        $chainedDimUnionNewOrExtra = $this->tryMatchInlineCallArgChainedDimUnionNewAndExtraProducer(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp,
            $block,
            $calleeName
        );
        if (false === $chainedDimUnionNewOrExtra) {
            return null;
        }
        if (null !== $chainedDimUnionNewOrExtra) {
            return $chainedDimUnionNewOrExtra;
        }
        if ($producerCount === $argCount) {
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
        }
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

        return null;
    }
}
