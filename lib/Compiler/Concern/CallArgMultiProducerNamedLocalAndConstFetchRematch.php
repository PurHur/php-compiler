<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;

/**
 * Post-tookDim multi-producer (>=2) / named-local / ConstFetch→adjacent remap
 * (#36387 / #36403). First rematch after early residual valueSlot resolvers and
 * enum prefetch merge; caller keeps array_column / in_array follow-ons.
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot` / `$assignedNamedLocal` / `$sends` by-ref.
 * Requires `$cfgCallOp` and `$block->orig` non-null (caller guards). Mirrors
 * php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgMultiProducerNamedLocalAndConstFetchRematch
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $assignedNamedLocal
     */
    private function resolveCallArgMultiProducerNamedLocalAndConstFetchRematch(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        Op $cfgCallOp,
        array &$sends,
        &$valueSlot,
        &$assignedNamedLocal
    ): void {
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
    }
}
