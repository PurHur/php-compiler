<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Dead-temp / sibling-New_ / early named-CV inline call-arg producer match (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgProducerMatch} so gen-0 split-TU can hollow a
 * smaller Concern TU. Covers named-CV early exit, Phi+ConstFetch / arith+scalar /
 * New_+scalar / Include_+scalar dead-temp ordinal wiring, producer prelude filters,
 * array_walk New_+Closure, outer sibling FuncCall, unary hoisted arg0,
 * preg_replace_callback_array, filter-extension match, and sibling/positional/
 * trailing inline New_ family. Mirrors php-src Zend/zend_compile.c call-arg
 * operand wiring — move-only.
 *
 * `$producers` is by-ref: prelude filters must stick for later matchers in
 * {@see InlineCallArgProducerMatch::matchInlineCallArgProducer}.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait InlineCallArgDeadTempAndSiblingNewProducers
{
    /**
     * @param list<Op\Expr> $producers filtered in place when no early match
     * @param list<Operand> $callArgs
     * @return Op\Expr|null matched producer; null to continue later heuristics
     */
    private function tryMatchInlineCallArgDeadTempAndSiblingNewProducer(
        array &$producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $callArg = $callArgs[$argIndex] ?? null;
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        // php-cfg distinct Var operands per name — never steal preceding Assign/New slots (#15658).
        if (null !== $callArg && null !== Block::resolveVariableName($callArg)) {
            if (
                0 === $argIndex
                && 'var_export' === $inlineFuncName
                && $this->producersAreSiblingArithmeticWithHoistedScalarConstFetch($producers)
            ) {
                foreach ($producers as $producer) {
                    if ($this->isChainedArithmeticBinaryOpExpr($producer)) {
                        return $producer;
                    }
                }
            }
            // register_shutdown_function(fn(...), E::A) — php-cfg dead temps per arg (#5751).
            if (
                'register_shutdown_function' === $inlineFuncName
                && 2 === \count($callArgs)
                && $this->callArgIsDeadInlineTemporary($callArg)
                && \count($producers) >= 2
            ) {
                $closureProducer = null;
                $enumFetch = null;
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                        $closureProducer = $producer;
                    } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                        $enumFetch = $producer;
                    }
                }
                if (null !== $closureProducer && null !== $enumFetch) {
                    return 0 === $argIndex ? $closureProducer : $enumFetch;
                }
            }

            return null;
        }
        // f(cond ? a : b, true) / f(true, cond ? a : b) — Phi-written ?: temps + hoisted
        // true/false/null ConstFetch as sibling dead temps (#22732, re-#15816).
        // php-cfg leaves distinct empty arg Vars; without Phi vs ConstFetch discrimination both
        // ARG_SENDs bind the ConstFetch (or both the ternary phi).
        if (
            null !== $callArg
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $phiWrittenIndexes = [];
            $constFetchWrittenIndexes = [];
            foreach ($callArgs as $i => $candidate) {
                if (
                    !($candidate instanceof Operand)
                    || !$this->callArgIsDeadInlineTemporary($candidate)
                    || $this->isEmbeddedCallLiteralArg($candidate)
                ) {
                    continue;
                }
                if ($this->callArgTemporaryIsPhiWritten($candidate)) {
                    $phiWrittenIndexes[] = (int) $i;
                } elseif ($this->callArgTemporaryIsScalarConstFetchWritten($candidate)) {
                    $constFetchWrittenIndexes[] = (int) $i;
                }
            }
            if ([] !== $phiWrittenIndexes && [] !== $constFetchWrittenIndexes) {
                if (\in_array($argIndex, $constFetchWrittenIndexes, true)) {
                    // Prefer the ConstFetch embedded on the Temporary — preceding producer
                    // lists are often empty for leading true/false/null before ?: (#22732).
                    foreach ($callArg->ops ?? [] as $embedded) {
                        if (!$embedded instanceof Op\Expr\ConstFetch) {
                            continue;
                        }
                        $embeddedName = $this->staticNameFromOperand($embedded->name);
                        if (
                            null !== $embeddedName
                            && \in_array(strtolower($embeddedName), ['true', 'false', 'null'], true)
                        ) {
                            return $embedded;
                        }
                    }
                    $scalarConsts = [];
                    foreach ($producers as $producer) {
                        if (!$producer instanceof Op\Expr\ConstFetch) {
                            continue;
                        }
                        $name = $this->staticNameFromOperand($producer->name);
                        if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                            continue;
                        }
                        if (
                            (
                                null !== $producer->result
                                && $this->operandsReferToSameVariable($producer->result, $callArg)
                            )
                            || (
                                isset($callArg->ops)
                                && \is_array($callArg->ops)
                                && \in_array($producer, $callArg->ops, true)
                            )
                        ) {
                            return $producer;
                        }
                        $scalarConsts[] = $producer;
                    }
                    $constOrdinal = \array_search($argIndex, $constFetchWrittenIndexes, true);
                    if (false !== $constOrdinal && isset($scalarConsts[$constOrdinal])) {
                        return $scalarConsts[$constOrdinal];
                    }
                }
                if (\in_array($argIndex, $phiWrittenIndexes, true)) {
                    // Leave for resolveNestedTernaryMergeCallArgSlot — do not steal ConstFetch.
                    return null;
                }
            }
        }
        // f($x + 1, …, true) — Plus/arith + ConstFetch true/false/null as sibling dead temps (#19515).
        // php-cfg leaves distinct empty arg Vars; without ordinal wiring both ARG_SENDs bind the ConstFetch.
        if (
            $this->producersAreSiblingArithmeticWithHoistedScalarConstFetch($producers)
            && null !== $callArg
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $arith = null;
            $scalarConst = null;
            foreach ($producers as $producer) {
                if ($this->isChainedArithmeticBinaryOpExpr($producer)) {
                    $arith = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                        $scalarConst = $producer;
                    }
                }
            }
            if (null !== $arith && null !== $scalarConst) {
                $deadTempIndexes = [];
                foreach ($callArgs as $i => $candidate) {
                    if (
                        $candidate instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($candidate)
                        && !$this->isEmbeddedCallLiteralArg($candidate)
                    ) {
                        $deadTempIndexes[] = (int) $i;
                    }
                }
                if (2 === \count($deadTempIndexes)) {
                    if ($argIndex === $deadTempIndexes[0]) {
                        return $arith;
                    }
                    if ($argIndex === $deadTempIndexes[1]) {
                        return $scalarConst;
                    }
                }
            }
        }
        // iterator_to_array(new ArrayIterator([...]), false) — Array_ ctor prelude + New_ + trailing
        // true/false/null ConstFetch as sibling dead temps (#22702, re-#11321). Without ordinal wiring,
        // both ARG_SENDs bind the New_ slot and preserve_keys becomes object-truthy (always true).
        if (
            null !== $callArg
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $inlineNew = null;
            $scalarConst = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\New_ && null === $inlineNew) {
                    $inlineNew = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                        $scalarConst = $producer;
                    }
                }
            }
            if (null !== $inlineNew && null !== $scalarConst) {
                $deadTempIndexes = [];
                foreach ($callArgs as $i => $candidate) {
                    if (
                        $candidate instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($candidate)
                        && !$this->isEmbeddedCallLiteralArg($candidate)
                    ) {
                        $deadTempIndexes[] = (int) $i;
                    }
                }
                if (2 === \count($deadTempIndexes)) {
                    if ($argIndex === $deadTempIndexes[0]) {
                        return $inlineNew;
                    }
                    if ($argIndex === $deadTempIndexes[1]) {
                        return $scalarConst;
                    }
                }
            }
        }
        // var_export(require_once $f, true) / print_r(include $f, true) — Include_/Eval_ + trailing
        // true/false/null ConstFetch as sibling dead temps (#25852, #21938). Without ordinal wiring,
        // ARG_SEND steals earlier getmypid()/file_put_contents() temps from the same CFG block.
        if (
            null !== $callArg
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $includeProducer = null;
            $scalarConst = null;
            foreach ($producers as $producer) {
                if (
                    ($producer instanceof Op\Expr\Include_ || $producer instanceof Op\Expr\Eval_)
                    && null === $includeProducer
                ) {
                    $includeProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                        $scalarConst = $producer;
                    }
                }
            }
            if (null !== $includeProducer && null !== $scalarConst) {
                $deadTempIndexes = [];
                foreach ($callArgs as $i => $candidate) {
                    if (
                        $candidate instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($candidate)
                        && !$this->isEmbeddedCallLiteralArg($candidate)
                    ) {
                        $deadTempIndexes[] = (int) $i;
                    }
                }
                if (2 === \count($deadTempIndexes)) {
                    if ($argIndex === $deadTempIndexes[0]) {
                        return $includeProducer;
                    }
                    if ($argIndex === $deadTempIndexes[1]) {
                        return $scalarConst;
                    }
                }
            }
        }
        $producers = $this->filterDeadClassConstFetchInlineProducers($producers);
        $producers = $this->filterNestedNewInlineCallArgProducers($producers, $cfgCallOp);
        $producers = $this->filterKnownVoidMethodCallPreludes($producers);
        $producers = $this->filterStmtLevelArrayPointerFuncPreludes($producers);
        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — drop stmt-level StaticCalls when
        // StaticPropertyFetch producers cover the dead-temp args (#34997).
        $producers = $this->filterStmtLevelStaticCallBeforeStaticPropertyFetchProducers(
            $producers,
            $callArgs
        );
        // array_walk(new ArrayObject([...]), fn(...)) — New_ + Closure hoisted before consumer (#17504).
        if (
            \in_array($inlineFuncName, ['array_walk', 'array_walk_recursive'], true)
            && 2 === \count($callArgs)
            && null !== $cfgCallOp
            && null !== $block
        ) {
            $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
            if (
                $leadingCallback instanceof Op\Expr\Closure
                || $leadingCallback instanceof Op\Expr\ArrowFunction
            ) {
                $inlineNewSubject = $this->leadingInlineNewBeforeCallbackBeforeCfgCall($cfgCallOp, $block);
                if ($inlineNewSubject instanceof Op\Expr\New_) {
                    if (0 === $argIndex) {
                        return $inlineNewSubject;
                    }
                    if (1 === $argIndex) {
                        return $leadingCallback;
                    }

                    return null;
                }
            }
        }
        // is_array(file(..., FLAGS)) — dead temp may alias bitmask OR, not file() result (#10474).
        if (
            0 === $argIndex
            && null !== $callArg
            && $this->callArgIsDeadInlineTemporary($callArg)
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            foreach (array_reverse($producers) as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    return $producer;
                }
            }
        }
        $hoistedScalar = $this->matchHoistedScalarConstFetchInlineCallArgProducer($producers, $callArg);
        if (null !== $hoistedScalar) {
            return $hoistedScalar;
        }
        if (
            null !== $cfgCallOp
            && null !== $block
            && null !== $block->orig
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex && $callIndex > 0) {
                $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex(
                    $callIndex,
                    $block->orig->children
                );
                if (null !== $firstSibling) {
                    $outer = $this->outerSiblingInlineFuncCallProducers(
                        $firstSibling,
                        $callIndex,
                        $block->orig->children
                    );
                    $hoistedArgCount = 0;
                    foreach ($callArgs as $hoistedArg) {
                        if (null !== $hoistedArg && !$this->isEmbeddedCallLiteralArg($hoistedArg)) {
                            ++$hoistedArgCount;
                        }
                    }
                    if (
                        \count($outer) === $hoistedArgCount
                        && $hoistedArgCount >= 2
                        && \count($outer) < $callIndex - $firstSibling
                    ) {
                        // A::inc(); A::inc(); var_dump(A::$n, B::$n) — intervening
                        // StaticPropertyFetch producers are the ARG_SEND sources; stmt-level
                        // void StaticCalls must not steal the dead-temp args (#34997).
                        // var_dump($g(), $h()) has no intervening fetches, so still binds outer.
                        if (!$this->interveningFetchProducersCoverDeadTempCallArgs(
                            $firstSibling,
                            $callIndex,
                            $block->orig->children,
                            $cfgCallOp
                        )) {
                            $leadingEmbedded = 0;
                            foreach ($callArgs as $embeddedArg) {
                                if ($this->isEmbeddedCallLiteralArg($embeddedArg)) {
                                    ++$leadingEmbedded;
                                    continue;
                                }
                                break;
                            }
                            $outerOrdinal = $argIndex - $leadingEmbedded;
                            if ($outerOrdinal >= 0 && isset($outer[$outerOrdinal])) {
                                return $outer[$outerOrdinal];
                            }

                            return null;
                        }
                    }
                }
                $immediate = $block->orig->children[$callIndex - 1] ?? null;
                if (
                    ($immediate instanceof Op\Expr\FuncCall || $immediate instanceof Op\Expr\NsFuncCall)
                    && $this->isAdjacentNestedFuncCallProducer(
                        $immediate,
                        $cfgCallOp,
                        $callIndex - 1,
                        $callIndex
                    )
                ) {
                    $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName);
                    $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                    if (
                        $callbackArgIndex >= 0
                        && 2 === \count($callArgs)
                        && $argIndex === $callbackArgIndex
                        && ($leadingCallback instanceof Op\Expr\ArrowFunction
                            || $leadingCallback instanceof Op\Expr\Closure
                            || $leadingCallback instanceof Op\Expr\FirstClassCallable)
                    ) {
                        // array_map(intval(...), str_split(...)) — haystack sibling must not bind callback (#15487, #16279).
                    } else {
                        return $immediate;
                    }
                }
            }
        }
        if (
            0 === $argIndex
            && null !== $cfgCallOp
            && null !== $block
            && null !== $block->orig
            && $this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex && $callIndex > 0) {
                $immediate = $block->orig->children[$callIndex - 1] ?? null;
                if ($immediate instanceof Op\Expr\UnaryMinus || $immediate instanceof Op\Expr\UnaryPlus) {
                    return $immediate;
                }
            }
        }
        $producerCount = count($producers);
        $argCount = count($callArgs);
        if (
            'preg_replace_callback_array' === $inlineFuncName
            && 2 === $argCount
            && $producerCount >= 2
        ) {
            $arrayProducer = null;
            $enumFetch = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ClassConstFetch) {
                    $enumFetch = $producer;
                }
            }
            if (null !== $arrayProducer && null !== $enumFetch) {
                return 0 === $argIndex ? $arrayProducer : $enumFetch;
            }
        }
        $filterInline = $this->matchFilterExtensionInlineCallArgProducer(
            $producers,
            $callArgs,
            $argIndex,
            $inlineFuncName
        );
        if (null !== $filterInline) {
            return $filterInline;
        }
        // new DatePeriod(new DateTime(...), new DateInterval(...), …) — positional sibling New_ (#17524).
        if (null !== $block && null !== $cfgCallOp) {
            $siblingNews = $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp);
            if ([] !== $siblingNews) {
                $matched = $this->matchSiblingInlineNewCallArgProducer($siblingNews, $callArgs, $argIndex);
                if (null !== $matched) {
                    return $matched;
                }
                $callArg = $callArgs[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && $this->callArgIsDeadInlineTemporary($callArg)
                    && $this->callArgIsNewExpression($callArg)
                    && isset($siblingNews[$argIndex])
                    && $siblingNews[$argIndex] instanceof Op\Expr\New_
                ) {
                    return $siblingNews[$argIndex];
                }
                if (
                    null !== $callArg
                    && $this->callArgIsDeadInlineTemporary($callArg)
                    && $this->callArgIsNewExpression($callArg)
                    && 1 === \count($siblingNews)
                    && ($producers[$argIndex] ?? null) instanceof Op\Expr\New_
                    && $siblingNews[0] === $producers[$argIndex]
                ) {
                    return $siblingNews[0];
                }
                // iterator_count(new DatePeriod(...)) — inner ctor New_ hoists must not bind arg #0 (#14483).
                if (
                    1 === \count($siblingNews)
                    && 1 === \count($callArgs)
                    && 0 === $argIndex
                ) {
                    $callArg = $callArgs[$argIndex] ?? null;
                    if (null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg)) {
                        return $siblingNews[0];
                    }
                }
            }
        }
        $siblingInlineNew = $this->matchSiblingInlineNewCallArgProducer($producers, $callArgs, $argIndex);
        if (null !== $siblingInlineNew) {
            return $siblingInlineNew;
        }
        // new LimitIterator(new ArrayIterator([...]), …) — Array_ is inner-ctor prelude (#12916).
        $nestedCtorNew = $this->matchNestedNewCtorInlineNewProducer($producers, $argIndex, $argCount, $callArgs);
        if (null !== $nestedCtorNew) {
            return $nestedCtorNew;
        }
        $positionalInlineNew = $this->matchPositionalInlineNewCallArgProducer($producers, $callArgs, $argIndex);
        if (null !== $positionalInlineNew) {
            return $positionalInlineNew;
        }
        $trailingInlineNew = $this->matchTrailingInlineNewCallArgProducer($producers, $callArgs, $argIndex);
        if (null !== $trailingInlineNew) {
            return $trailingInlineNew;
        }

        return null;
    }
}
