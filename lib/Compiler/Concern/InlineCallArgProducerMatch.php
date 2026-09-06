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
 * CompileCallArgSends: matchInlineCallArgProducer*. Specialized matchers
 * (array_splice / mbstring / filter / embedded literals) live in
 * {@see MatchInlineCallArgProducerWithEmbeddedLiterals}.
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
        if (0 === $producerCount) {
            return null;
        }
        if (
            \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
            && 2 === $argCount
            && $argIndex < $producerCount
        ) {
            $mergeMapped = $this->matchArrayMergeFamilyInlineCallArgProducer($producers, $argIndex);
            if (null !== $mergeMapped) {
                return $mergeMapped;
            }
        }
        if (\in_array($inlineFuncName, ['preg_replace', 'substr_replace'], true)) {
            $pregReplaceMapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $pregReplaceMapped) {
                return $pregReplaceMapped;
            }
        }
        $trailingComparator = $this->matchTrailingComparatorInlineCallArgProducer(
            $producers,
            $callArgs,
            $argIndex,
            $inlineFuncName
        );
        if (null !== $trailingComparator) {
            return $trailingComparator;
        }
        $siblingNestedArray = $this->matchSiblingNestedArrayLiteralCallArgProducer(
            $producers,
            $argIndex,
            $argCount
        );
        if (null !== $siblingNestedArray) {
            return $siblingNestedArray;
        }
        $foldedFirstNested = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
            $producers,
            $argIndex,
            $argCount,
            $callArgs
        );
        if (null !== $foldedFirstNested) {
            return $foldedFirstNested;
        }
        $soleNestedHaystack = $this->matchSoleNestedInlineArrayHaystackProducer(
            $producers,
            $callArgs,
            $argIndex
        );
        if (null !== $soleNestedHaystack) {
            return $soleNestedHaystack;
        }
        if ('array_combine' === $inlineFuncName && 2 === $argCount && $producerCount >= 2) {
            $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
            if (null !== $arrayCombinePair) {
                return $arrayCombinePair;
            }
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
        // php-cfg hoists `$a = [...]` as Array_+Assign before `array_key_exists('k', $a)` (#9456).
        $callArg = $callArgs[$argIndex] ?? null;
        if (
            $this->callArgIsDeadInlineTemporary($callArg)
            && null !== $cfgCallOp
            && null !== $block
            && null !== $block->orig
            && !(
                $producerCount > $argCount
                && null !== $this->soleNonEmbeddedCallArgIndex($callArgs)
            )
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
                    // Nested inline array consumed multiple Array_ slots — do not wire trailing int arg (#12008, #13697).
                    $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                        $producers,
                        $argIndex,
                        $argCount,
                        $producerCount
                    );
                    if (null !== $outerArray) {
                        return $outerArray;
                    }
                    $trailingConst = $this->matchNestedArrayTrailingConstFetchCallArgProducer(
                        $producers,
                        $callArgs,
                        $argIndex
                    );
                    if (null !== $trailingConst) {
                        return $trailingConst;
                    }
                    $byIndex = null;
                }
                if ($byIndex instanceof Op\Expr\Array_ && null !== $callArg) {
                    // array_reverse([...], true) — nested FuncCall feeds the dead temp, not hoisted Array_ (#14042).
                    $directCall = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                    if (
                        (
                            $directCall instanceof Op\Expr\FuncCall
                            || $directCall instanceof Op\Expr\NsFuncCall
                            || $directCall instanceof Op\Expr\StaticCall
                            || $directCall instanceof Op\Expr\MethodCall
                        )
                        && !$this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        return $directCall;
                    }
                }
                if (
                    null !== $byIndex
                    && !(
                        $byIndex instanceof Op\Expr\Array_
                        && $this->producersIncludeInlineArrayUnionPlus($producers)
                    )
                    && (
                        $byIndex instanceof Op\Expr\FuncCall
                        || $byIndex instanceof Op\Expr\NsFuncCall
                        || $byIndex instanceof Op\Expr\StaticCall
                        || $byIndex instanceof Op\Expr\MethodCall
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $byIndex instanceof Op\Expr\BinaryOp\BitwiseXor
                        || $byIndex instanceof Op\Expr\ClassConstFetch
                        || $byIndex instanceof Op\Expr\Cast
                        || $this->inlineCallArgProducerUsesExprResultSlot($byIndex)
                    )
                ) {
                    if ($byIndex instanceof Op\Expr\ClassConstFetch && null !== $callArg) {
                        $enumPropertyProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                        if ($enumPropertyProducer instanceof Op\Expr\PropertyFetch
                            || $enumPropertyProducer instanceof Op\Expr\NullsafePropertyFetch
                            || $enumPropertyProducer instanceof Op\Expr\NullsafeMethodCall) {
                            return $enumPropertyProducer;
                        }
                    }
                    if (
                        (
                            $byIndex instanceof Op\Expr\FuncCall
                            || $byIndex instanceof Op\Expr\NsFuncCall
                            || $byIndex instanceof Op\Expr\StaticCall
                            || $byIndex instanceof Op\Expr\MethodCall
                        )
                        && null !== $callArg
                        && $this->callArgOperandExpectsArrayProducer($callArg)
                    ) {
                        // array_slice([..], array_search(...)) — nested int builtin is arg #1 (#13684).
                        $arrayProducers = array_values(array_filter(
                            $producers,
                            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                        ));
                        if (isset($arrayProducers[$argIndex])) {
                            return $arrayProducers[$argIndex];
                        }
                        if (0 === $argIndex && [] !== $arrayProducers) {
                            return $arrayProducers[0];
                        }
                        // in_array('x', g(), true) — hoisted FuncCall haystack, not Array_ (#16265).
                        if (
                            \in_array($inlineFuncName, ['in_array', 'array_search'], true)
                            && 1 === $argIndex
                        ) {
                            return $byIndex;
                        }
                    } else {
                        if ($byIndex instanceof Op\Expr\Array_) {
                            $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                                $producers,
                                $argIndex,
                                $argCount,
                                $producerCount
                            );
                            if (null !== $outerArray) {
                                return $outerArray;
                            }
                        }

                        return $byIndex;
                    }
                }
                if (
                    null !== $byIndex
                    && $byIndex instanceof Op\Expr\ConstFetch
                    && null !== $callArg
                    && !$this->callArgOperandExpectsArrayProducer($callArg)
                ) {
                    foreach ($producers as $candidate) {
                        if (
                            $candidate instanceof Op\Expr\Cast
                            && $this->operandsReferToSameVariable($candidate->expr, $byIndex->result)
                        ) {
                            return $candidate;
                        }
                    }
                    $castProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                    if ($castProducer instanceof Op\Expr\Cast) {
                        return $castProducer;
                    }
                    $last = $producers[$producerCount - 1] ?? null;
                    // ini_set('error_reporting', (string)(E_ALL & ~MASK)) — ConstFetch prelude + trailing Cast (#15460).
                    if ($last instanceof Op\Expr\Cast) {
                        return $last;
                    }
                    // error_reporting(E_ALL & ~E_NOTICE) — ConstFetch prelude + trailing BitwiseAnd (#15391).
                    if (1 === $argCount) {
                        if (
                            $last instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $last instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $last instanceof Op\Expr\BinaryOp\BitwiseXor
                        ) {
                            return $last;
                        }
                    }

                    return $byIndex;
                }
            }
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

            return null;
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

                    return $trailing[$argIndex - 1] ?? null;
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

                return null;
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
                return null;
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

                return null;
            }

            return $mapped;
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
