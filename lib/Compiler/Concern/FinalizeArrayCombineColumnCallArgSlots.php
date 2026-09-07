<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * array_combine / array_column last-chance call-arg slots (#36387 / #36403).
 *
 * Extracted from {@see FinalizeArrayFamilyCallArgSlots} so gen-0 split-TU can
 * hollow a smaller Concern TU. Mirrors php-src Zend/zend_builtin_functions.c
 * `array_combine` / `array_column` and Zend/zend_compile.c call-arg edges when
 * php-cfg linearizes nested `array_keys()` + trailing Array_ / nested haystack
 * + hoisted null (#15558, #15857, #15914, #16080). Move-only; no behavior change
 * intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FinalizeArrayFamilyCallArgSlots).
 */
trait FinalizeArrayCombineColumnCallArgSlots
{
    /**
     * Last-chance ARG_SEND slot for array_combine() nested array_keys() + trailing Array_ (#15558, #15857).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayCombineCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? []) || null === $block->orig) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall) {
            $funcOrdinal = 0;
            foreach ($producers as $producer) {
                if ($producer === $matched) {
                    break;
                }
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                    ++$funcOrdinal;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            if (null === $block->slotForOperand($matched->result)) {
                foreach ($this->compileExpr($matched, $block) as $op) {
                    $sends[] = $op;
                }
            }
            $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
            if (null !== $execSlot) {
                return $execSlot;
            }
            $slot = $block->slotForOperand($matched->result);
            if (null !== $slot) {
                return (string) $slot;
            }

            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        if ($matched instanceof Op\Expr\Array_) {
            $ordinalSlot = $this->slotForArrayCombineSiblingInitArray(
                $block,
                $producers,
                $argIndex,
                $sends
            );
            if (null !== $ordinalSlot) {
                return $ordinalSlot;
            }
            $byArgSlot = $this->slotForArrayCombineInitArrayByArgIndex($block, $cfgCallOp, $argIndex, $sends);
            if (null !== $byArgSlot) {
                return $byArgSlot;
            }
        }
        $slot = $block->slotForOperand($matched->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return null;
    }

    /**
     * array_combine() with inline array literals — map arg index to INIT_ARRAY ordinal (#16080).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineInitArrayByArgIndex(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array $pendingSends
    ): ?string {
        if (2 !== \count($cfgCallOp->args ?? [])) {
            return null;
        }
        foreach ($cfgCallOp->args as $callArg) {
            if (
                null === $callArg
                || !$this->callArgIsDeadInlineTemporary($callArg)
                || !$this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return null;
            }
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);
        if (\count($initSlots) <= $argIndex) {
            return null;
        }

        return $initSlots[$argIndex];
    }

    /**
     * array_combine([...], [...]) — map arg index to sibling INIT_ARRAY slot (#16080, #10214).
     *
     * @param list<OpCode> $pendingSends
     */
    private function slotForArrayCombineSiblingInitArray(
        Block $block,
        array $producers,
        int $argIndex,
        array $pendingSends
    ): ?string {
        $matched = $this->matchArrayCombineInlineProducers($producers, $argIndex);
        if (!$matched instanceof Op\Expr\Array_) {
            return null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if (2 !== \count($arrayProducers)) {
            return null;
        }
        $ordinal = array_search($matched, $arrayProducers, true);
        if (false === $ordinal) {
            return null;
        }
        $initSlots = $this->initArraySlotsForCurrentFunccall($block, $pendingSends);

        return $initSlots[$ordinal] ?? null;
    }

    /**
     * Last-chance ARG_SEND slot for array_column() inline nested haystack + hoisted null (#15914).
     *
     * @param list<OpCode> $sends
     */
    private function finalizeArrayColumnCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        array &$sends
    ): ?string {
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        $callArgs = $cfgCallOp->args ?? [];
        $matched = $this->matchArrayColumnNestedHaystackTrailingProducers(
            $producers,
            $callArgs,
            $argIndex,
            $cfgCallOp
        );
        // array_column([['n'=>'a'], …], 'n') — nested haystack without trailing null (#13703, #15960).
        if (!$matched instanceof Op\Expr && 0 === $argIndex) {
            $matched = $this->matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
                $producers,
                $argIndex,
                \count($callArgs),
                $callArgs
            );
            if (null === $matched) {
                $matched = $this->matchSoleNestedInlineArrayHaystackProducer(
                    $producers,
                    $callArgs,
                    $argIndex
                );
            }
            if (null === $matched) {
                $matched = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            }
        }
        if (!$matched instanceof Op\Expr) {
            return null;
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $sends[] = $op;
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * array_column([[..]], null, 'x') / array_column([[..]], 'name', null) — nested haystack + null (#15914).
     *
     * @param list<Op\Expr> $producers
     * @param list<Operand> $callArgs
     */
    private function matchArrayColumnNestedHaystackTrailingProducers(
        array $producers,
        array $callArgs,
        int $argIndex,
        ?Op $cfgCallOp
    ): ?Op\Expr {
        $nestedTrailing = $this->splitNestedArrayLiteralChainWithTrailingProducers($producers);
        if (null === $nestedTrailing) {
            return null;
        }
        [$arrayChain, $trailing] = $nestedTrailing;
        if (0 === $argIndex) {
            return $arrayChain[\count($arrayChain) - 1];
        }
        if ($this->isEmbeddedCallLiteralArg($callArgs[$argIndex] ?? null)) {
            return null;
        }
        $nullFetch = null;
        foreach ($trailing as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null !== $name && 'null' === strtolower($name)) {
                $nullFetch = $producer;
                break;
            }
        }
        if (null === $nullFetch) {
            return null;
        }
        $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
        if (null !== $nullTarget && $argIndex === $nullTarget) {
            return $nullFetch;
        }
        if ($argIndex === \count($callArgs) - 1) {
            return $nullFetch;
        }

        return null;
    }

    /**
     * Hoisted null ConstFetch before array_column() maps to column_key or index_key (#4306, #9305, #10535).
     */
    private function arrayColumnNullPreludeArgIndex(?Op $cfgCallOp): ?int
    {
        if (null === $cfgCallOp || !\is_array($cfgCallOp->args ?? null)) {
            return null;
        }
        $args = $cfgCallOp->args;
        $argc = \count($args);
        if (2 === $argc) {
            return 1;
        }
        if (3 !== $argc) {
            return null;
        }
        $columnEmbedded = $this->isEmbeddedCallLiteralArg($args[1] ?? null);
        $indexEmbedded = $this->isEmbeddedCallLiteralArg($args[2] ?? null);
        if ($columnEmbedded && !$indexEmbedded) {
            return 2;
        }
        if (!$columnEmbedded && $indexEmbedded) {
            return 1;
        }

        return null;
    }
}
