<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Post-preferNamedLocal early valueSlot wiring (#36387 / #36403): multi-producer
 * match / named-local bind, array_column prelude, in_array/array_search/
 * array_key_exists haystack+strict, and array_pad haystack/length.
 *
 * Extracted from {@see CompileCallArgSends} after residual dim/coalesce/New_
 * + closure/dead-temp/eval/preferNamedLocal so gen-0 split-TU can hollow a
 * smaller Concern TU. Mutates `$valueSlot` / `$sends` / `$assignedNamedLocal` /
 * `$inlineArrayLiteralArgWired` by-ref. Mirrors php-src Zend/zend_compile.c
 * call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgMultiProducerNamedLocalArrayColumnSearchPadValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $assignedNamedLocal
     * @param-out bool $inlineArrayLiteralArgWired
     */
    private function resolveCallArgMultiProducerNamedLocalArrayColumnSearchPadValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array &$sends,
        &$valueSlot,
        &$assignedNamedLocal,
        bool &$inlineArrayLiteralArgWired
    ): void {
        if (null !== $cfgCallOp && null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                $block->orig->children,
                $cfgCallOp
            );
            $namedLocalSlot = $this->namedLocalCallArgSlotIfBound($arg, $block, $cfgCallOp, (int) $argIndex);
            if (null === $namedLocalSlot && null === $assignedNamedLocal) {
                $assignedNamedLocal = $this->slotForNamedLocalFromAssignVarOperand($arg, $block);
            }
            $callArgNamed = Block::resolveVariableName($cfgCallOp->args[(int) $argIndex] ?? $arg);
            if (
                \count($producers) >= 2
                && null === $namedLocalSlot
                && null === $assignedNamedLocal
                && (null === $callArgNamed || '' === $callArgNamed)
            ) {
                $matched = $this->matchInlineCallArgProducer(
                    $producers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block,
                    $calleeName
                );
                if ($matched instanceof Op\Expr) {
                    $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    $matched = $this->preferSiblingCallOverNestedArrayInlineMatch(
                        $matched,
                        $producers,
                        $callArgProbe
                    );
                }
                if (
                    ($matched instanceof Op\Expr\ConstFetch || $matched instanceof Op\Expr\ClassConstFetch)
                    && null !== $cfgCallOp
                    && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall(
                        $matched,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                ) {
                    $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $adjacentSlot) {
                        $valueSlot = $adjacentSlot;
                        $matched = null;
                    }
                }
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $matchedSlot = $this->slotForEmittedIssetOrEmptyProducer($block, $matched)
                        ?? (
                            $matched instanceof Op\Expr\New_
                                ? $this->slotForInlineNewProducer($block, $matched, $sends)
                                : $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    $block->orig->children
                                )
                        );
                    if (null !== $matchedSlot && null === $valueSlot) {
                        $valueSlot = $matchedSlot;
                    }
                }
            } elseif (null !== $namedLocalSlot) {
                $valueSlot = $namedLocalSlot;
            } elseif (null !== $assignedNamedLocal) {
                $valueSlot = (string) $assignedNamedLocal;
            }
            if ('array_column' === strtolower($calleeName ?? '')) {
                if (0 === $argIndex) {
                    $arrayExpr = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    if ($arrayExpr instanceof Op\Expr\Array_) {
                        if (null === $block->slotForOperand($arrayExpr->result)) {
                            foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $arraySlot = $block->slotForOperand($arrayExpr->result);
                        if (null !== $arraySlot) {
                            $valueSlot = $arraySlot;
                        }
                    }
                } elseif (1 === $argIndex || 2 === $argIndex) {
                    $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
                    if ($nullTarget === $argIndex) {
                        foreach ($block->orig->children as $i => $child) {
                            if ($child === $cfgCallOp) {
                                $prev = $block->orig->children[$i - 1] ?? null;
                                if ($prev instanceof Op\Expr\ConstFetch) {
                                    $name = $this->staticNameFromOperand($prev->name);
                                    if (null !== $name && 'null' === strtolower($name)) {
                                        if (null === $block->slotForOperand($prev->result)) {
                                            foreach ($this->compileExpr($prev, $block) as $op) {
                                                $sends[] = $op;
                                            }
                                        }
                                        $nullSlot = $block->slotForOperand($prev->result);
                                        if (null !== $nullSlot) {
                                            $valueSlot = $nullSlot;
                                        }
                                    }
                                }
                                break;
                            }
                        }
                    }
                }
            }
            if (
                \in_array(strtolower($calleeName ?? ''), ['in_array', 'array_search', 'array_key_exists'], true)
                && null !== $block->orig
            ) {
                $arraySearchProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                if (1 === (int) $argIndex) {
                    $haystackArg = $cfgCallOp->args[1] ?? $arg;
                    if (
                        null !== $haystackArg
                        && $this->callArgIsCoalesceMergeProducer($haystackArg, $block, $cfgCallOp, 1)
                    ) {
                        $coalesceHaystackSlot = $this->compileCallArgCoalesceSlot(
                            $haystackArg,
                            $block,
                            $cfgCallOp,
                            1
                        );
                        if (null !== $coalesceHaystackSlot) {
                            $valueSlot = (string) $coalesceHaystackSlot;
                        }
                    }
                    if (
                        null === $valueSlot
                        && null !== $haystackArg
                        && $this->callArgOperandExpectsArrayProducer($haystackArg)
                    ) {
                        $haystackArrayProducer = $this->matchInlineArraySearchHaystackProducer(
                            $arraySearchProducers,
                            $haystackArg
                        );
                        if ($haystackArrayProducer instanceof Op\Expr\Array_) {
                            $haystackSlot = $block->slotForOperand($haystackArrayProducer->result);
                            if (null === $haystackSlot) {
                                foreach ($this->compileArrayLiteral($haystackArrayProducer, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $haystackSlot = $block->slotForOperand($haystackArrayProducer->result);
                            }
                            if (null !== $haystackSlot) {
                                $valueSlot = (string) $haystackSlot;
                            }
                        }
                        if (null === $valueSlot) {
                            $constFuncSplit = $this->splitLeadingConstFetchWithFuncCallCallArg($arraySearchProducers);
                            $funcHaystack = null;
                            if (null !== $constFuncSplit) {
                                [, $funcHaystack] = $constFuncSplit;
                            } elseif (
                                2 === \count($arraySearchProducers)
                                && ($arraySearchProducers[0] instanceof Op\Expr\FuncCall
                                    || $arraySearchProducers[0] instanceof Op\Expr\NsFuncCall)
                                && $arraySearchProducers[1] instanceof Op\Expr\ConstFetch
                            ) {
                                $funcHaystack = $arraySearchProducers[0];
                            }
                            if ($funcHaystack instanceof Op\Expr) {
                                if (null === $block->slotForOperand($funcHaystack->result)) {
                                    foreach ($this->compileExpr($funcHaystack, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $haystackFuncSlot = $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $funcHaystack,
                                    $cfgCallOp,
                                    $block->orig->children
                                );
                                if (null !== $haystackFuncSlot) {
                                    $valueSlot = (string) $haystackFuncSlot;
                                }
                            }
                        }
                    }
                } elseif (
                    2 === (int) $argIndex
                    && \in_array(strtolower($calleeName ?? ''), ['in_array', 'array_search'], true)
                ) {
                    $strictArg = $cfgCallOp->args[2] ?? $arg;
                    foreach ($arraySearchProducers as $producer) {
                        if (!$producer instanceof Op\Expr\ConstFetch) {
                            continue;
                        }
                        $strictName = $this->staticNameFromOperand($producer->name);
                        if (
                            null === $strictName
                            || !\in_array(strtolower($strictName), ['true', 'false'], true)
                        ) {
                            continue;
                        }
                        if (
                            null !== $strictArg
                            && !$this->operandsReferToSameVariable($producer->result, $strictArg)
                        ) {
                            continue;
                        }
                        if (null === $block->slotForOperand($producer->result)) {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $strictSlot = $block->slotForOperand($producer->result);
                        if (null !== $strictSlot) {
                            $valueSlot = (string) $strictSlot;
                        }
                        break;
                    }
                } elseif (0 === (int) $argIndex) {
                    foreach ($block->orig->children as $i => $child) {
                        if ($child !== $cfgCallOp) {
                            continue;
                        }
                        $callArg = $cfgCallOp->args[0] ?? null;
                        for ($j = $i - 1; $j >= 0; --$j) {
                            $prev = $block->orig->children[$j] ?? null;
                            if (!$prev instanceof Op) {
                                continue;
                            }
                            if ($prev instanceof Op\Expr\ConstFetch) {
                                if (
                                    null !== $callArg
                                    && $this->operandsReferToSameVariable($prev->result, $callArg)
                                ) {
                                    if (null === $block->slotForOperand($prev->result)) {
                                        foreach ($this->compileExpr($prev, $block) as $op) {
                                            $sends[] = $op;
                                        }
                                    }
                                    $needleSlot = $block->slotForOperand($prev->result);
                                    if (null !== $needleSlot) {
                                        $valueSlot = $needleSlot;
                                    }
                                    break 2;
                                }
                                continue;
                            }
                            if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            if (
                'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? $calleeName ?? '')
                && null !== $block->orig
            ) {
                $padProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                if (0 === (int) $argIndex) {
                    foreach ($padProducers as $producer) {
                        if (!$producer instanceof Op\Expr\Array_) {
                            continue;
                        }
                        $padArraySlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                        if (null === $padArraySlot) {
                            $padArraySlot = $block->slotForOperand($producer->result);
                        }
                        if (null === $padArraySlot) {
                            foreach ($this->compileArrayLiteral($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $padArraySlot = $this->slotForInitArrayOrdinal($block, 0, $sends)
                                ?? $block->slotForOperand($producer->result);
                        }
                        if (null !== $padArraySlot) {
                            $valueSlot = (string) $padArraySlot;
                            // hold([]); array_pad([...], -N, 0) — do not let sibling EXEC_RETURN clobber haystack (#15421, #16066).
                            $inlineArrayLiteralArgWired = true;
                        }
                        break;
                    }
                } elseif (1 === (int) $argIndex) {
                    foreach ($padProducers as $producer) {
                        if (
                            !$producer instanceof Op\Expr\UnaryMinus
                            && !$producer instanceof Op\Expr\UnaryPlus
                        ) {
                            continue;
                        }
                        if (null === $block->slotForOperand($producer->result)) {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $lengthSlot = $block->slotForOperand($producer->result);
                        if (null !== $lengthSlot) {
                            $valueSlot = (string) $lengthSlot;
                            $inlineArrayLiteralArgWired = true;
                        }
                        break;
                    }
                }
            }
        }
    }
}
