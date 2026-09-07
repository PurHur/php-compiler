<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Null-literal / hoisted isset-empty / date_sun / array_splice / mbstring /
 * property-or-class-const / bool-null fold / const-prelude / synced coalesce /
 * global ConstFetch early valueSlot wiring (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Mutates `$valueSlot`, `$nullLiteralCallArgSlot`, and
 * `$hoistedEnumPropertyCallArgSlotWired` by-ref before the dimFetch / inlineArray
 * if-elseif chain. Mirrors php-src Zend/zend_compile.c call-arg send operand
 * wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgNullLiteralHoistedPropertyConstAndCoalesceValueSlots
{
    /**
     * @param list<\PHPCompiler\OpCode> $sends already-emitted ARG_SENDs (const prelude may append)
     * @param-out mixed $valueSlot
     * @param-out mixed $nullLiteralCallArgSlot
     * @param-out bool $hoistedEnumPropertyCallArgSlotWired
     */
    private function resolveCallArgNullLiteralHoistedPropertyConstAndCoalesceValueSlots(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?Op $cfgCallOp,
        mixed $callArgOperand,
        array &$sends,
        &$valueSlot,
        &$nullLiteralCallArgSlot,
        &$hoistedEnumPropertyCallArgSlotWired
    ): void {
        if (null !== $cfgCallOp) {
            $nullLiteralArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if (
                $nullLiteralArg instanceof Operand
                && $this->callArgIsNullLiteral(
                    $nullLiteralArg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
            ) {
                $nullLiteralCallArgSlot = (string) $this->registerNullConstantSlot($block, $nullLiteralArg);
                $valueSlot = $nullLiteralCallArgSlot;
            }
        }
        $hoistedEnumPropertyCallArgSlotWired = false;
        if (null !== $cfgCallOp && !$this->isCallArgDirectArrayDimFetch($arg)) {
            $valueSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
        }
        if (null !== $cfgCallOp && null !== $block->orig) {
            $dateSunSlot = $this->wireDateSunFuncHoistedCallArgSlot($block, $cfgCallOp, (int) $argIndex);
            if (null !== $dateSunSlot) {
                $valueSlot = $dateSunSlot;
            }
            if (null === $valueSlot) {
                $arraySpliceSlot = $this->wireArraySpliceUnaryOffsetReplacementCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $arraySpliceSlot) {
                    $valueSlot = $arraySpliceSlot;
                }
            }
            if (null === $valueSlot) {
                $mbstringSlot = $this->wireMbstringUnaryOffsetNullLengthCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $mbstringSlot) {
                    $valueSlot = $mbstringSlot;
                }
            }
        }
        // E::A->name / E::A?->name in call args — wire PropertyFetch slot before enum const fold (#10286, #9684).
        if (null === $valueSlot && null !== $cfgCallOp) {
            $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall($block, $cfgCallOp);
            if (null !== $immediatePropertySlot) {
                $valueSlot = $immediatePropertySlot;
                $hoistedEnumPropertyCallArgSlotWired = true;
            } else {
                $hoistedPropertyOrConstSlot = $this->slotForHoistedClassConstFetchCallArg(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $hoistedPropertyOrConstSlot) {
                    $valueSlot = $hoistedPropertyOrConstSlot;
                    $hoistedEnumPropertyCallArgSlotWired = true;
                }
            }
        }
        if (null === $valueSlot && null !== $cfgCallOp) {
            $hoistedScalarSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                $callArgOperand,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $hoistedScalarSlot) {
                $valueSlot = (string) $hoistedScalarSlot;
            }
        }
        if (null === $valueSlot && null !== $cfgCallOp) {
            $hoistedConstPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $hoistedConstPreludeSlot) {
                $valueSlot = (string) $hoistedConstPreludeSlot;
            }
        }
        $syncedCoalesceSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgOperand);
        if (null === $syncedCoalesceSlot) {
            $syncedCoalesceSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($arg);
        }
        if (null !== $syncedCoalesceSlot && null === $valueSlot) {
            $valueSlot = (string) $syncedCoalesceSlot;
        }
        if (null === $valueSlot) {
            $coalesceArgSlot = $this->compileCallArgCoalesceSlot(
                $callArgOperand,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null === $coalesceArgSlot) {
                $coalesceArgSlot = $this->compileCallArgCoalesceSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
            }
            if (null !== $coalesceArgSlot) {
                $valueSlot = (string) $coalesceArgSlot;
            }
        }
        $callArgConstRoot = $this->unwrapOperandChain($callArgOperand);
        if ($callArgConstRoot instanceof Op\Expr\ConstFetch && null === $valueSlot) {
            $foldedGlobalConst = $this->tryFoldGlobalConstFetch($callArgConstRoot);
            if (null !== $foldedGlobalConst) {
                $valueSlot = (string) $block->registerConstant($callArgOperand, $foldedGlobalConst);
            }
        }
    }
}
