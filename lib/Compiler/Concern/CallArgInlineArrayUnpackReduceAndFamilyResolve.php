<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-PostFcc inline Array_ unpack / array_reduce early ARG_SEND /
 * filter_var|array_combine|array_merge|substr_replace|proc_open family resolve
 * (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$inlineArray` + nested-func flags by-ref for later valueSlot
 * wiring; returns true when array_reduce already appended an ARG_SEND (caller continues).
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgInlineArrayUnpackReduceAndFamilyResolve
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $inlineArray resolved Array_/FuncCall producer or null
     * @param-out bool $arrayCombineNestedFuncArg
     * @param-out bool $arrayMergeNestedFuncArg
     * @return bool true when an ARG_SEND was appended — caller continues
     */
    private function tryCompileCallArgInlineArrayUnpackReduceAndFamilyResolve(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?Op $cfgCallOp,
        array $args,
        mixed $nameSlot,
        mixed $unpackFlag,
        mixed $dimFetchSlot,
        array &$sends,
        &$inlineArray,
        &$arrayCombineNestedFuncArg,
        &$arrayMergeNestedFuncArg
    ): bool {
        $inlineArray = null === $dimFetchSlot
            ? $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp, (int) $argIndex)
            : null;
        if (
            null !== $unpackFlag
            && null !== $cfgCallOp
            && null !== $block->orig
        ) {
            $unpackMatch = $this->matchInlineArrayProducersToArrayCallArgs(
                $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                $cfgCallOp->args ?? [],
                (int) $argIndex
            );
            if ($unpackMatch instanceof Op\Expr\Array_) {
                $inlineArray = $unpackMatch;
            }
        }
        // array_reduce([...], fn(...), [...]) — bind each Array_/closure ARG_SEND by producer ordinal (#5626).
        if (
            null !== $cfgCallOp
            && 'array_reduce' === $this->resolveCfgFuncCallName($cfgCallOp)
            && null !== $block->orig
            && $this->arrayReduceCfgCallHasMultipleInlineArrayProducers($block, $cfgCallOp)
        ) {
            $reduceProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $reduceMatched = $this->matchInlineCallArgProducer(
                $reduceProducers,
                $cfgCallOp->args ?? [],
                (int) $argIndex,
                $cfgCallOp,
                $block
            );
            if ($reduceMatched instanceof Op\Expr\Array_) {
                $reduceSlot = $this->slotForInitArrayProducerBeforeCfgCall(
                    $block,
                    $cfgCallOp,
                    $reduceMatched,
                    $sends
                );
                if (null !== $reduceSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $reduceSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    return true;
                }
            }
            if (
                $reduceMatched instanceof Op\Expr\Closure
                || $reduceMatched instanceof Op\Expr\ArrowFunction
                || $reduceMatched instanceof Op\Expr\FirstClassCallable
            ) {
                $closureSlot = $block->slotForOperand($reduceMatched->result);
                if (null === $closureSlot) {
                    foreach ($this->compileExpr($reduceMatched, $block) as $op) {
                        $sends[] = $op;
                    }
                    $closureSlot = $block->slotForOperand($reduceMatched->result);
                }
                if (null !== $closureSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $closureSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    return true;
                }
            }
        }
        if (null !== $inlineArray && null !== $cfgCallOp && null !== $block->orig) {
            $consumerCallIndex = null;
            foreach ($block->orig->children as $ci => $cfgChild) {
                if ($cfgChild === $cfgCallOp) {
                    $consumerCallIndex = $ci;
                    break;
                }
            }
            if (\is_int($consumerCallIndex) && $consumerCallIndex > 0) {
                $immediatePrelude = $block->orig->children[$consumerCallIndex - 1] ?? null;
                if ($immediatePrelude instanceof Op\Expr\Isset_ || $immediatePrelude instanceof Op\Expr\Empty_) {
                    // isset(['a'=>1]['a']) / empty([...]) — prelude owns dim semantics (#16462).
                    $inlineArray = null;
                }
            }
        }
        if (
            null !== $inlineArray
            && null !== $cfgCallOp
            && 2 === (int) $argIndex
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['filter_var', 'filter_input'],
                true
            )
        ) {
            // Flat ['flags'=>FILTER_*] defers to ConstFetch+Array_ wiring (#12326).
            // Nested ['options'=>[...]] must keep the outermost Array_ (#12007, #22772).
            $filterProducers = null !== $block->orig
                ? $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                : [];
            $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($filterProducers);
            if (null !== $leadingConstNested) {
                [, $arrayChain] = $leadingConstNested;
                $inlineArray = $arrayChain[\count($arrayChain) - 1];
            } else {
                $inlineArray = null;
            }
        }
        $arrayCombineNestedFuncArg = false;
        $arrayMergeNestedFuncArg = false;
        if (null !== $cfgCallOp && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp) && null !== $block->orig) {
            $combineProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $combineMatch = $this->matchArrayCombineInlineProducers($combineProducers, (int) $argIndex);
            if ($combineMatch instanceof Op\Expr\Array_) {
                $inlineArray = $combineMatch;
            } elseif (
                $combineMatch instanceof Op\Expr\FuncCall
                || $combineMatch instanceof Op\Expr\NsFuncCall
            ) {
                // array_combine(array_keys(...), [...]) — arg #0 is nested FuncCall, not inner haystack Array_ (#15558, #16097).
                $inlineArray = $combineMatch;
                $arrayCombineNestedFuncArg = true;
            }
        }
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && null === $unpackFlag
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                true
            )
        ) {
            $mergeCallArg = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                $mergeCallArg instanceof Operand
                && $this->callArgIsDeadInlineTemporary($mergeCallArg)
                && $this->callArgOperandExpectsArrayProducer($mergeCallArg)
            ) {
                $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                    $block->orig->children,
                    $cfgCallOp
                );
                $mergeMatch = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                    $mergeProducers,
                    (int) $argIndex,
                    \count($cfgCallOp->args ?? []),
                    $cfgCallOp->args ?? []
                );
                if (null === $mergeMatch) {
                    $mergeMatch = $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                        $mergeProducers,
                        (int) $argIndex
                    );
                }
                if ($mergeMatch instanceof Op\Expr\Array_) {
                    $inlineArray = $mergeMatch;
                } elseif (
                    $mergeMatch instanceof Op\Expr\FuncCall
                    || $mergeMatch instanceof Op\Expr\NsFuncCall
                ) {
                    // array_merge(array_keys(...), [...]) — arg #0 is sibling FuncCall, not trailing Array_ (#12450, #16418).
                    $inlineArray = $mergeMatch;
                    $arrayMergeNestedFuncArg = true;
                }
            }
        }
        if (null === $inlineArray && null !== $cfgCallOp) {
            if (null === $inlineArray && 'substr_replace' === $this->resolveCfgFuncCallName($cfgCallOp)) {
                $substrReplaceProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $substrReplaceMatch = $this->matchInlineArrayProducersToArrayCallArgs(
                    $substrReplaceProducers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex
                );
                if ($substrReplaceMatch instanceof Op\Expr\Array_) {
                    $inlineArray = $substrReplaceMatch;
                }
            }
            if (null === $inlineArray && !$arrayCombineNestedFuncArg && !$arrayMergeNestedFuncArg) {
                $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                    $callArgProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
                    $arrayCombineArgZeroSiblingFunc = false;
                    if (
                        0 === (int) $argIndex
                        && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
                        && null !== $block->orig
                    ) {
                        $combineArg0 = $this->matchArrayCombineInlineProducers(
                            $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                            0
                        );
                        $arrayCombineArgZeroSiblingFunc = $combineArg0 instanceof Op\Expr\FuncCall
                            || $combineArg0 instanceof Op\Expr\NsFuncCall;
                    }
                    if (
                        $arrayCombineArgZeroSiblingFunc
                        || (
                            $this->callArgIsDeadInlineTemporary($callArgProbe)
                            && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                            && $this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                                $cfgCallOp,
                                (int) $argIndex,
                                $block
                            )
                        )
                    ) {
                        // array_combine(array_keys(...), [...]) — arg #0 is sibling FuncCall, not trailing Array_ (#15558, #13776).
                    } elseif (
                        0 === (int) $argIndex
                        && null !== $block->orig
                        && $this->inlineCallArgZeroFedByHoistedCastProducer($block->orig->children, $cfgCallOp)
                    ) {
                        // array_merge((object)[...], [...]) — Cast feeds arg #0 (#15858).
                    } elseif (
                        ($this->callArgIsDeadInlineTemporary($callArgProbe)
                            && $this->callArgOperandExpectsArrayProducer($callArgProbe))
                        || $this->operandsReferToSameVariable($stmtBeforeArray->result, $callArgProbe)
                        || ($this->operandsReferToSameVariable($stmtBeforeArray->result, $arg)
                            && $this->callArgOperandExpectsArrayProducer($arg))
                    ) {
                        $inlineArray = $stmtBeforeArray;
                    }
                }
            }
        }
        $callArgOperand = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
        if (null !== $inlineArray && null !== $cfgCallOp && null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $inlineArrayIdx = array_search($inlineArray, $producers, true);
            if (
                false !== $inlineArrayIdx
                && ($producers[$inlineArrayIdx + 1] ?? null) instanceof Op\Expr\New_
            ) {
                // Inline new call arg — inner Array_ is ctor prelude, not the wired operand (#13342).
                $inlineArray = null;
            }
        }
        if (
            null !== $inlineArray
            && null !== $callArgOperand
            && !$this->callArgOperandExpectsArrayProducer($callArgOperand)
        ) {
            // new C([...]) — php-cfg often leaves the ctor arg Temporary untyped; the
            // stmt-before Array_ is the real operand (static/param defaults) (#22390).
            // Typed null ahead of Array_ must not keep the Array_ on arg #0 (#22770).
            $keepUntypedNewCtorArray = $cfgCallOp instanceof Op\Expr\New_
                && $this->newCtorDeadTempMayBindStmtBeforeArray(
                    $callArgOperand,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
                && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray;
            if (!$keepUntypedNewCtorArray) {
                $producers = (null !== $cfgCallOp && null !== $block->orig)
                    ? $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    : [];
                $outerArray = [] !== $producers
                    ? $this->matchOutermostNestedInlineArrayProducerForArgZero(
                        $producers,
                        (int) $argIndex,
                        \count($cfgCallOp->args ?? $args),
                        \count($producers)
                    )
                    : null;
                $inlineArray = $outerArray instanceof Op\Expr\Array_ ? $outerArray : null;
            }
        }
        if (
            null !== $inlineArray
            && null !== $cfgCallOp
            && 1 === (int) $argIndex
            && 'proc_open' === $this->resolveCfgFuncCallName($cfgCallOp)
            && null !== $block->orig
        ) {
            $procOpenProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $procOpenArrayProducers = array_values(array_filter(
                $procOpenProducers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            $procOpenOuter = $this->matchOutermostNestedInlineArrayProducerForCallArg(
                $procOpenProducers,
                $procOpenArrayProducers,
                (int) $argIndex,
                \count($cfgCallOp->args ?? $args)
            );
            if ($procOpenOuter instanceof Op\Expr\Array_) {
                $inlineArray = $procOpenOuter;
            }
        }
        return false;
    }
}
