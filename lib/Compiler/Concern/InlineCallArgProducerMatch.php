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
 * CompileCallArgSends: matchInlineCallArgProducer* + specialized matchers.
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

    /**
     * Map hoisted inline producers when php-cfg embeds literal call args (#8561, #8796).
     *
     * e.g. in_array(1, [1, 2, 3], true) — producers [Array_, ConstFetch] align to args 1 and 2, not 0.
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchArraySpliceUnaryOffsetReplacementProducers(
        array $producers,
        int $argIndex,
        int $argCount,
        ?string $inlineFuncName
    ): ?Op\Expr {
        if ('array_splice' !== $inlineFuncName || $argCount < 4 || 2 !== \count($producers)) {
            return null;
        }
        $unaryProducer = null;
        $replacementProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $unaryProducer = $producer;
            } elseif ($producer instanceof Op\Expr\Array_) {
                $replacementProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && 'null' === strtolower($name)) {
                    $replacementProducer = $producer;
                }
            }
        }
        if (null === $unaryProducer || null === $replacementProducer) {
            return null;
        }
        if (1 === $argIndex) {
            return $unaryProducer;
        }
        if ($argIndex === $argCount - 1) {
            return $replacementProducer;
        }

        return null;
    }

    /**
     * mb_substr($s, -N, null[, $enc]) / mb_strcut — hoisted UnaryMinus offset + null length (#16481).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchMbstringUnaryOffsetNullLengthProducers(
        array $producers,
        int $argIndex,
        int $argCount,
        ?string $inlineFuncName
    ): ?Op\Expr {
        $func = strtolower((string) $inlineFuncName);
        if (!\in_array($func, ['mb_substr', 'mb_strcut'], true) || $argCount < 3 || 2 !== \count($producers)) {
            return null;
        }
        $unaryProducer = null;
        $nullProducer = null;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $unaryProducer = $producer;
            } elseif ($producer instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($producer->name);
                if (null !== $name && 'null' === strtolower($name)) {
                    $nullProducer = $producer;
                }
            }
        }
        if (null === $unaryProducer || null === $nullProducer) {
            return null;
        }
        if (1 === $argIndex) {
            return $unaryProducer;
        }
        if (2 === $argIndex) {
            return $nullProducer;
        }

        return null;
    }

    private function matchFilterExtensionInlineCallArgProducer(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?string $inlineFuncName
    ): ?Op\Expr {
        if ('filter_var' === $inlineFuncName && 3 === \count($callArgs)) {
            $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($producers);
            if (null !== $leadingConstNested) {
                [$constFetch, $arrayChain] = $leadingConstNested;

                return match ($argIndex) {
                    1 => $constFetch,
                    2 => $arrayChain[\count($arrayChain) - 1],
                    default => null,
                };
            }
            $leadingConstArray = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
            if (null !== $leadingConstArray) {
                [$constFetch, $array] = $leadingConstArray;

                return match ($argIndex) {
                    1 => $constFetch,
                    2 => $array,
                    default => null,
                };
            }
        }
        if ('filter_input' === $inlineFuncName && 4 === \count($callArgs)) {
            $constFetches = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
            ));
            $arrayProducers = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if (1 === \count($constFetches) && \count($arrayProducers) >= 1) {
                return match ($argIndex) {
                    2 => $constFetches[0],
                    3 => $arrayProducers[\count($arrayProducers) - 1],
                    default => null,
                };
            }
            if (\count($constFetches) >= 2 && [] !== $arrayProducers) {
                return match ($argIndex) {
                    0 => $constFetches[0],
                    2 => $constFetches[1],
                    3 => $arrayProducers[\count($arrayProducers) - 1],
                    default => null,
                };
            }
        }

        return null;
    }

    private function matchInlineCallArgProducerWithEmbeddedLiterals(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp = null,
        ?Block $block = null,
        ?string $calleeName = null
    ): ?Op\Expr {
        $inlineFuncName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $filterInline = $this->matchFilterExtensionInlineCallArgProducer(
            $producers,
            $callArgs,
            $argIndex,
            $inlineFuncName
        );
        if (null !== $filterInline) {
            return $filterInline;
        }
        $mappedArraySplice = $this->matchArraySpliceUnaryOffsetReplacementProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $inlineFuncName
        );
        if (null !== $mappedArraySplice) {
            return $mappedArraySplice;
        }
        $mappedMbstring = $this->matchMbstringUnaryOffsetNullLengthProducers(
            $producers,
            $argIndex,
            \count($callArgs),
            $inlineFuncName
        );
        if (null !== $mappedMbstring) {
            return $mappedMbstring;
        }
        // array_combine([...], [...]) — sibling Array_ producers map to keys/values by order (#10214).
        if (
            'array_combine' === $inlineFuncName
            && 2 === \count($callArgs)
            && \count($producers) >= 2
        ) {
            $arrayCombinePair = $this->matchArrayCombineInlineProducers($producers, $argIndex);
            if (null !== $arrayCombinePair) {
                return $arrayCombinePair;
            }
        }
        // array_merge(array_keys($src), ['b']) / array_merge(['a'=>1], array_keys(...)) (#12450, #13704, #13760).
        if (
            \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
            && 2 === \count($callArgs)
            && \count($producers) >= 2
        ) {
            $arrayMergePair = $this->matchArrayMergeFamilyInlineCallArgProducer($producers, $argIndex);
            if (null !== $arrayMergePair) {
                return $arrayMergePair;
            }
        }
        // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch prelude + sibling FuncCall (#15833).
        if ('explode' === $inlineFuncName && 2 === \count($callArgs) && \count($producers) >= 2) {
            $constProducer = null;
            $funcProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                }
            }
            if (null !== $constProducer && null !== $funcProducer) {
                return (0 === $argIndex) ? $constProducer : $funcProducer;
            }
        }
        // fseek($stream, -1, SEEK_END) — hoisted UnaryMinus offset + ConstFetch whence (#16523, #13451).
        if ('fseek' === $inlineFuncName && \count($callArgs) >= 3) {
            $unaryProducer = null;
            $constProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                }
            }
            if (null !== $unaryProducer && null !== $constProducer) {
                return match ($argIndex) {
                    1 => $unaryProducer,
                    2 => $constProducer,
                    default => null,
                };
            }
        }
        // preg_split(..., -1, PREG_SPLIT_*) / explode(..., -1) — limit/flags from UnaryMinus/ConstFetch, not prior sibling FuncCall (#13423, #13424).
        if (
            ('preg_split' === $inlineFuncName && ($argIndex === 2 || $argIndex === 3))
            || ('explode' === $inlineFuncName && 2 === $argIndex)
        ) {
            $unaryProducer = null;
            $constProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constProducer = $producer;
                }
            }
            if (2 === $argIndex) {
                return $unaryProducer;
            }
            if (3 === $argIndex) {
                return $constProducer;
            }
        }
        // json_decode(g(), true) / json_decode(json_encode($d), true) — FuncCall + bool ConstFetch (#24137).
        if ('json_decode' === $inlineFuncName && \count($callArgs) >= 2 && \count($callArgs) < 4) {
            $funcProducer = null;
            $boolConstProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $boolConstProducer = $producer;
                    }
                }
            }
            if (null !== $funcProducer && null !== $boolConstProducer) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    1 => $boolConstProducer,
                    default => null,
                };
            }
        }
        // json_decode(g(), true, 512, JSON_THROW_ON_ERROR) — FuncCall + ConstFetch preludes (#12009, #15441).
        if ('json_decode' === $inlineFuncName && \count($callArgs) >= 4) {
            $funcProducer = null;
            $constProducers = [];
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $constProducers[] = $producer;
                }
            }
            if (null !== $funcProducer && \count($constProducers) >= 2) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    1 => $constProducers[0],
                    3 => $constProducers[\count($constProducers) - 1],
                    default => null,
                };
            }
        }
        // array_chunk(range(1,5), 2, true) — FuncCall haystack + trailing preserve_keys ConstFetch (#11767).
        if ('array_chunk' === $inlineFuncName && \count($callArgs) >= 3) {
            $funcProducer = null;
            $boolConstProducer = null;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    $funcProducer = $producer;
                } elseif ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $boolConstProducer = $producer;
                    }
                }
            }
            if (null !== $funcProducer && null !== $boolConstProducer) {
                return match ($argIndex) {
                    0 => $funcProducer,
                    2 => $boolConstProducer,
                    default => null,
                };
            }
        }
        if (null !== $cfgCallOp && null !== $block && null !== $block->orig) {
            $leadingProducer = $producers[0] ?? null;
            if ($leadingProducer instanceof Op\Expr\FuncCall || $leadingProducer instanceof Op\Expr\NsFuncCall) {
                $callArg = $callArgs[$argIndex] ?? null;
                if (null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg)) {
                    $byIndex = $this->inlineHoistedProducerForCallArgIndex(
                        $cfgCallOp,
                        $argIndex,
                        $producers,
                        $block->orig->children,
                        $block
                    );
                    if ($byIndex instanceof Op\Expr) {
                        return $byIndex;
                    }
                }
            }
        }
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null !== $nestedTrailing) {
            [$arrayChain, $trailing] = $nestedTrailing;
            if (0 === $argIndex) {
                return $arrayChain[\count($arrayChain) - 1];
            }
            if ($argIndex === \count($callArgs) - 1 && [] !== $trailing) {
                return $trailing[\count($trailing) - 1];
            }
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $callArg = $callArgs[$argIndex] ?? null;
        if (null === $callArg) {
            return null;
        }
        foreach ($producers as $producer) {
            if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                if (
                    \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive'], true)
                    && ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                    && $argIndex > 0
                ) {
                    continue;
                }
                return $producer;
            }
        }

        // php-cfg dead call-arg temps: hoisted producers align to non-embedded arg slots (#9324).
        $nonEmbeddedArgIndices = [];
        foreach ($callArgs as $i => $arg) {
            if (null === $arg || $this->isEmbeddedCallLiteralArg($arg)) {
                continue;
            }
            if (
                null !== $cfgCallOp
                && null !== $block
                && $this->callArgUsesInlineArrayNotInHoistedProducers($arg, $block, $cfgCallOp, $i, $producers)
            ) {
                continue;
            }
            $nonEmbeddedArgIndices[] = $i;
        }
        // f(CONST, null|false|true, [...]) — multiple leading ConstFetches + trailing Array_
        // must map 1:1 to non-array dead-temp args (#22368; openssl_cms_verify null $certificates).
        // filter_var FILTER_* + ['flags'=>FILTER_*] keeps element ConstFetches in producers but only
        // one const call-arg — count mismatch skips this path.
        $leadingConstArrayMulti = $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers);
        if (null !== $leadingConstArrayMulti) {
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
            if (\count($leadingConsts) >= 2) {
                [, $arrayProducer] = $leadingConstArrayMulti;
                $arrayArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                $constArgIndices = [];
                foreach ($nonEmbeddedArgIndices as $idx) {
                    if ($idx !== $arrayArgIndex) {
                        $constArgIndices[] = $idx;
                    }
                }
                if (\count($leadingConsts) === \count($constArgIndices)) {
                    if ($argIndex === $arrayArgIndex) {
                        return $arrayProducer;
                    }
                    foreach ($constArgIndices as $ordinal => $idx) {
                        if ($argIndex === $idx) {
                            return $leadingConsts[$ordinal];
                        }
                    }

                    return null;
                }
            }
        }
        // substr(..., -N) / mb_substr(..., -N) — UnaryMinus maps to sole non-embedded offset/length (#13422, #13424).
        if (
            \in_array($inlineFuncName, ['substr', 'mb_substr', 'mb_strcut'], true)
            && 1 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && \in_array($argIndex, $nonEmbeddedArgIndices, true)
        ) {
            return $producers[0];
        }
        // preg_match(..., $matches, PREG_OFFSET_CAPTURE) — ConstFetch/BitwiseOr only for flags/offset, not &$matches (#13714).
        if (\in_array($inlineFuncName, ['preg_match', 'preg_match_all'], true)) {
            if (2 === $argIndex) {
                return null;
            }
            if ($argIndex >= 3) {
                $lastProducer = $producers[\count($producers) - 1] ?? null;
                if (
                    $lastProducer instanceof Op\Expr\ConstFetch
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseOr
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseAnd
                    || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseXor
                ) {
                    $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                    if ($argIndex === $trailingNonEmbedded) {
                        return $lastProducer;
                    }
                }
                foreach ($producers as $producer) {
                    if (
                        ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus)
                        && $argIndex === (\count($callArgs) - 1)
                    ) {
                        return $producer;
                    }
                }
            }

            return null;
        }
        // array_map(callback, [...], [...]) — map hoisted Array_ producers to array args by order (#10094, #13812).
        if ('array_map' === $inlineFuncName && $argIndex >= 1) {
            $mapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $mapped) {
                return $mapped;
            }
        }
        // preg_replace(['/a/'], ['A'], 'subj') — sibling Array_ pattern/replacement + embedded subject (#10808).
        // substr_replace(['a','b'], '.', [1,2], [1,1]) — sibling Array_ string/offset/length (#9124).
        if (\in_array($inlineFuncName, ['preg_replace', 'substr_replace'], true)) {
            $mapped = $this->matchInlineArrayProducersToArrayCallArgs($producers, $callArgs, $argIndex);
            if (null !== $mapped) {
                return $mapped;
            }
        }
        // strtotime('next Monday', strtotime('2024-06-03')) — lone hoisted FuncCall → sole non-embedded arg (#10838).
        if (
            1 === \count($producers)
            && 1 === \count($nonEmbeddedArgIndices)
            && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // in_array(null, [null], true) — Array_ haystack must not lose to hoisted null ConstFetch (#16096, re-#10909).
        if (
            \in_array($inlineFuncName, ['in_array', 'array_search'], true)
            && \count($callArgs) >= 3
        ) {
            $arrayProducerIndex = null;
            $strictBoolProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducerIndex = $pi;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $name = $this->staticNameFromOperand($producer->name);
                    if (null !== $name && \in_array(strtolower($name), ['true', 'false'], true)) {
                        $strictBoolProducerIndex = $pi;
                    }
                }
            }
            if (null !== $arrayProducerIndex) {
                if (1 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (2 === $argIndex && null !== $strictBoolProducerIndex) {
                    return $producers[$strictBoolProducerIndex];
                }
            }
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
                2 === \count($producers)
                && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
                && $producers[1] instanceof Op\Expr\ConstFetch
            ) {
                // in_array('x', g(), true) — cfg unshift order [FuncCall, ConstFetch] (#16265).
                if (1 === $argIndex) {
                    return $producers[0];
                }
                if (2 === $argIndex) {
                    return $producers[1];
                }
            }
        }
        // in_array(E::A, [E::A, E::B], true) — Array_ + trailing ConstFetch map to haystack/strict slots (#8796, #9888).
        if (\count($producers) >= 2) {
            $arrayProducerIndex = null;
            $constFetchIndices = [];
            $unaryProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    $arrayProducerIndex = $pi;
                } elseif ($producer instanceof Op\Expr\ConstFetch) {
                    $constFetchIndices[] = $pi;
                } elseif ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                    $unaryProducerIndex = $pi;
                }
            }
            // array_pad([E::A], N, E::B) — inline enum haystack Array_ + trailing pad-value ConstFetch (#8883).
            if (
                'array_pad' === $inlineFuncName
                && \count($callArgs) >= 3
                && null !== $arrayProducerIndex
                && [] !== $constFetchIndices
            ) {
                $padValueArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? 2;
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $padValueArgIndex) {
                    return $producers[$constFetchIndices[\count($constFetchIndices) - 1]];
                }

                return null;
            }
            // extract([...], EXTR_PREFIX_ALL, Prefix::A) — Array_ + ConstFetch + ClassConstFetch (#16041).
            if ('extract' === $inlineFuncName && 3 === \count($callArgs) && null !== $arrayProducerIndex) {
                $classConstIndex = null;
                foreach ($producers as $pi => $producer) {
                    if ($producer instanceof Op\Expr\ClassConstFetch) {
                        $classConstIndex = $pi;
                        break;
                    }
                }
                if (null !== $classConstIndex && [] !== $constFetchIndices) {
                    if (0 === $argIndex) {
                        return $producers[$arrayProducerIndex];
                    }
                    if (1 === $argIndex) {
                        return $producers[$constFetchIndices[0]];
                    }
                    if (2 === $argIndex) {
                        return $producers[$classConstIndex];
                    }

                    return null;
                }
            }
            // array_slice($a, -2, 2, true) — Array_ + UnaryMinus + trailing ConstFetch (#10579, #10809).
            if (
                null !== $arrayProducerIndex
                && null !== $unaryProducerIndex
                && 1 === \count($constFetchIndices)
                && \count($nonEmbeddedArgIndices) >= 3
            ) {
                $arrayArgIndex = $nonEmbeddedArgIndices[0] ?? null;
                $offsetArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $trailingArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $offsetArgIndex) {
                    return $producers[$unaryProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            $closureProducerIndex = null;
            foreach ($producers as $pi => $producer) {
                if ($producer instanceof Op\Expr\ArrowFunction
                    || $producer instanceof Op\Expr\Closure
                    || $producer instanceof Op\Expr\FirstClassCallable) {
                    $closureProducerIndex = $pi;
                    break;
                }
            }
            // array_reduce([...], fn(...), null|false|…) — Array_ + Closure + trailing ConstFetch (#23571).
            // Must run before the generic Array_+ConstFetch mapper below, which would bind the
            // Array_ onto the callback slot (nonEmbeddedArgIndices[1]) and orphan the Closure.
            if (
                'array_reduce' === $inlineFuncName
                && null !== $arrayProducerIndex
                && null !== $closureProducerIndex
                && 1 === \count($constFetchIndices)
                && \count($callArgs) >= 3
            ) {
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (1 === $argIndex) {
                    return $producers[$closureProducerIndex];
                }
                if (2 === $argIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // array_reduce([...], [$this,'m'], 0) — input Array_ + object-array callable Array_;
            // initial is often an embedded Literal (no ConstFetch producer). Without this, both
            // dead temps bind the stmt-before Array_ and ARG_SEND duplicates the callback (#25766).
            if (
                'array_reduce' === $inlineFuncName
                && null === $closureProducerIndex
                && \count($callArgs) >= 2
            ) {
                $arrayProducerIndices = [];
                foreach ($producers as $pi => $producer) {
                    if ($producer instanceof Op\Expr\Array_) {
                        $arrayProducerIndices[] = $pi;
                    }
                }
                if (2 === \count($arrayProducerIndices)) {
                    if (0 === $argIndex) {
                        return $producers[$arrayProducerIndices[0]];
                    }
                    if (1 === $argIndex) {
                        return $producers[$arrayProducerIndices[1]];
                    }
                    if (2 === $argIndex && 1 === \count($constFetchIndices)) {
                        return $producers[$constFetchIndices[0]];
                    }

                    return null;
                }
            }
            if (null !== $arrayProducerIndex && 1 === \count($constFetchIndices) && \count($nonEmbeddedArgIndices) >= 3) {
                $arrayArgIndex = $nonEmbeddedArgIndices[1] ?? null;
                $literalArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
                if ($argIndex === $arrayArgIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if ($argIndex === $literalArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // array_filter($arr, fn(...) => ..., ARRAY_FILTER_USE_BOTH) — hoisted closure + trailing mode const (#10232, #9154).
            if (null !== $closureProducerIndex && 1 === \count($constFetchIndices) && \count($callArgs) >= 3) {
                $callbackArgIndex = null;
                foreach ($nonEmbeddedArgIndices as $idx) {
                    if ($idx > 0) {
                        $callbackArgIndex = $idx;
                        break;
                    }
                }
                $trailingArgIndex = \count($callArgs) - 1;
                if ($argIndex === $callbackArgIndex) {
                    return $producers[$closureProducerIndex];
                }
                if ($argIndex === $trailingArgIndex) {
                    return $producers[$constFetchIndices[0]];
                }

                return null;
            }
            // preg_replace_callback($pat, fn(...), $arr) / iterator_apply($it, fn(...), [$it]) — closure arg 1, array arg 2 (#10652, #15182).
            if (
                null !== $closureProducerIndex
                && null !== $arrayProducerIndex
                && \in_array($inlineFuncName, ['preg_replace_callback', 'iterator_apply'], true)
            ) {
                if (1 === $argIndex) {
                    return $producers[$closureProducerIndex];
                }
                if (2 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }

                return null;
            }
            // preg_replace_callback_array(['/a/' => fn(...)], $subj, -1[, &$count]) —
            // pattern-map Array_ is arg #0; UnaryMinus limit is arg #2 (closure is map value, #19697).
            if (
                'preg_replace_callback_array' === $inlineFuncName
                && null !== $arrayProducerIndex
            ) {
                if (0 === $argIndex) {
                    return $producers[$arrayProducerIndex];
                }
                if (2 === $argIndex && null !== $unaryProducerIndex) {
                    return $producers[$unaryProducerIndex];
                }

                return null;
            }
            // array_reduce([...], fn(...), [...]) — input Array_(s) + closure + initial Array_
            // (#5626). Generic pair logic below takes the *last* Array_ as the sole haystack and
            // returns null for arg #2, scrambling input/callback/initial when both are inline.
            if (
                'array_reduce' === $inlineFuncName
                && null !== $closureProducerIndex
                && \count($callArgs) >= 3
            ) {
                $arrayReduceWired = $this->matchArrayReduceInlineArrayClosureInitialProducer(
                    $producers,
                    $argIndex,
                    $closureProducerIndex
                );
                if (null !== $arrayReduceWired) {
                    return $arrayReduceWired;
                }
            }
            // array_map(fn(...), [...]) / array_reduce([...], fn(...)) — closure + inline Array_ (#10651, #10775).
            // Guard callbackArgIndex >= 0: otherwise 1-(-1)=2 binds limit/flags to the Array_ (#19697).
            if (null !== $closureProducerIndex && null !== $arrayProducerIndex) {
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex(
                    $inlineFuncName
                );
                if ($callbackArgIndex >= 0) {
                    $arrayArgIndex = 1 - $callbackArgIndex;
                    if ($argIndex === $callbackArgIndex) {
                        return $producers[$closureProducerIndex];
                    }
                    if ($argIndex === $arrayArgIndex) {
                        return $producers[$arrayProducerIndex];
                    }

                    return null;
                }
            }
            // array_map(intval(...), str_split(...)) / array_filter(str_split(...), is_numeric(...)) — FCC + inline FuncCall haystack (#15487, #15490, #15961).
            if (null !== $closureProducerIndex && null === $arrayProducerIndex) {
                $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($inlineFuncName);
                $haystackProducer = null;
                if (null !== $cfgCallOp && null !== $block) {
                    $haystackProducer = 1 === $callbackArgIndex
                        ? $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block)
                        : $this->leadingCallbackFirstHaystackFuncCallBeforeCfgCall($cfgCallOp, $block);
                }
                if (null === $haystackProducer) {
                    $funcCallHaystackIndex = null;
                    foreach ($producers as $pi => $producer) {
                        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                            $funcCallHaystackIndex = $pi;
                        }
                    }
                    if (null !== $funcCallHaystackIndex) {
                        $haystackProducer = $producers[$funcCallHaystackIndex];
                    }
                }
                if (
                    null !== $haystackProducer
                    && $callbackArgIndex >= 0
                    && 2 === \count($callArgs)
                ) {
                    $arrayArgIndex = 1 - $callbackArgIndex;
                    if ($argIndex === $callbackArgIndex) {
                        return $producers[$closureProducerIndex];
                    }
                    if ($argIndex === $arrayArgIndex) {
                        return $haystackProducer;
                    }

                    return null;
                }
            }
        }
        // array_column([[..],[..]], 'name', null) — outer Array_ + trailing null ConstFetch (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[0];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[1];
            }

            return null;
        }
        // array_column([[..],[..]], 'name', null) — legacy arg0-only guard when only one non-embedded slot (#9305).
        if (
            2 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && $producers[1] instanceof Op\Expr\ConstFetch
            && 0 === ($nonEmbeddedArgIndices[0] ?? -1)
            && 0 === $argIndex
            && 1 === \count($nonEmbeddedArgIndices)
        ) {
            return $producers[0];
        }
        // in_array(E::A, [E::A, E::B]) — lone Array_ maps to haystack slot, not enum needle (#8796, #9888).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && \count($nonEmbeddedArgIndices) >= 2
            && \count($producers) < \count($nonEmbeddedArgIndices)
        ) {
            $arrayArgIndex = $nonEmbeddedArgIndices[\count($producers)];

            return $argIndex === $arrayArgIndex ? $producers[0] : null;
        }
        // array_column([['x'=>1]], 'x') — lone outer Array_ maps to first non-embedded arg (#9305, #10042).
        if (
            1 === \count($producers)
            && $producers[0] instanceof Op\Expr\Array_
            && 1 === \count($nonEmbeddedArgIndices)
            && $argIndex === $nonEmbeddedArgIndices[0]
        ) {
            return $producers[0];
        }
        // array_slice($b, 1, -2) — lone UnaryMinus maps to trailing non-embedded arg (#10579).
        if (
            1 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            $unaryArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1];

            return $argIndex === $unaryArgIndex ? $producers[0] : null;
        }
        // preg_match*() PREG_* | PREG_* — ConstFetch preludes + dead-temp BitwiseOr (#10517, #3148).
        $lastProducer = $producers[\count($producers) - 1] ?? null;
        if (
            $lastProducer instanceof Op\Expr\BinaryOp\BitwiseOr
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $lastProducer instanceof Op\Expr\BinaryOp\BitwiseXor
        ) {
            $trailingNonEmbedded = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $trailingNonEmbedded) {
                return $lastProducer;
            }
        }
        // filter_var('x', FILTER_*, ['flags' => FILTER_*]) — embedded arg 0 + ConstFetch/Array_ (#12326).
        // Single leading ConstFetch call-arg (element ConstFetches may also appear in producers).
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
            $arrayArgIndex = $nonEmbeddedArgIndices[\count($nonEmbeddedArgIndices) - 1] ?? null;
            if ($argIndex === $arrayArgIndex) {
                return $array;
            }
            $constArgIndices = [];
            foreach ($nonEmbeddedArgIndices as $idx) {
                if ($idx !== $arrayArgIndex) {
                    $constArgIndices[] = $idx;
                }
            }
            // Multi ConstFetch call-args already handled above (#22368); keep first-const fallback
            // when producers include array-element ConstFetches (filter_var flags-in-options).
            if (\count($leadingConsts) === \count($constArgIndices)) {
                foreach ($constArgIndices as $ordinal => $idx) {
                    if ($argIndex === $idx) {
                        return $leadingConsts[$ordinal];
                    }
                }

                return null;
            }
            foreach ($constArgIndices as $idx) {
                if ($argIndex === $idx) {
                    return $constFetch;
                }
            }

            return null;
        }
        // check('label', explode(..., -N), ['expect']) — UnaryMinus feeds nested callee; FuncCall + Array_ (#13424, strict_types).
        if (
            3 === \count($producers)
            && ($producers[0] instanceof Op\Expr\UnaryMinus || $producers[0] instanceof Op\Expr\UnaryPlus)
            && ($producers[1] instanceof Op\Expr\FuncCall || $producers[1] instanceof Op\Expr\NsFuncCall)
            && $producers[2] instanceof Op\Expr\Array_
            && \count($nonEmbeddedArgIndices) >= 2
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[1];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[2];
            }

            return null;
        }
        // check('label', builtin(...), ['expect'|'literal']) — FuncCall + trailing Array_/literal prelude (#13424).
        if (
            2 === \count($producers)
            && ($producers[0] instanceof Op\Expr\FuncCall || $producers[0] instanceof Op\Expr\NsFuncCall)
            && \count($nonEmbeddedArgIndices) >= 2
            && (
                $producers[1] instanceof Op\Expr\Array_
                || $producers[1] instanceof Op\Expr\ConstFetch
            )
        ) {
            if ($argIndex === $nonEmbeddedArgIndices[0]) {
                return $producers[0];
            }
            if ($argIndex === $nonEmbeddedArgIndices[1]) {
                return $producers[1];
            }

            return null;
        }
        // check('label', explode(..., -1), ['expect']) — lone hoisted FuncCall + trailing Array_ prelude (#13423, #13424).
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (
            null !== $soleHoisted
            && $argIndex === $soleHoisted
            && !(
                \in_array($inlineFuncName, ['array_merge', 'array_merge_recursive'], true)
                && \count($callArgs) >= 2
            )
        ) {
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    return $producer;
                }
            }
        }
        if (\count($producers) !== \count($nonEmbeddedArgIndices)) {
            return null;
        }
        if (null !== $block && null !== $callArg && null !== $this->slotForNamedLocalFromAssignVarOperand($callArg, $block)) {
            return null;
        }
        if ($this->isNamedVariableOperand($callArg)) {
            return null;
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return null;
        }
        if ('filter_input' === $inlineFuncName && 4 === \count($callArgs) && 0 === $argIndex) {
            return null;
        }

        return $producers[$producerOrdinal] ?? null;
    }
}
