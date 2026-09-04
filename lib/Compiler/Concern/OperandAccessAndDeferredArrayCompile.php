<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\ErrorSuppressBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;

/**
 * Operand read-slot finalization, call-return needs, deferred Array_ elements (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers finalizeOperandSlotForAccess / callNeedsReturnSlot and deferred Array_
 * element rematerialization used from compileOps / compileCallArgSends.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait OperandAccessAndDeferredArrayCompile
{
    /**
     * Assign.result temps diverge from the CV after by-ref builtins; reads must use the lvalue (#12712, #12714).
     */
    private function finalizeOperandSlotForAccess(Block $block, int $slot, bool $isRead): int
    {
        if (!$isRead) {
            return $slot;
        }
        $lvalue = $this->slotForAssignLvalueFromResultSlot($block, $slot);
        if (null !== $lvalue) {
            return $lvalue;
        }

        return $slot;
    }

    private function isDynamicVariableOperand(Operand\Variable $operand): bool
    {
        return !$operand->name instanceof Operand\Literal;
    }

    /**
     * php-cfg may leave call result usages empty when the next op is `return $tmp` (#1885).
     */
    private function callNeedsReturnSlot(Operand $result, Block $block, ?Op $cfgCallOp = null): bool
    {
        if (
            !empty($result->usages)
            || $block->callResultFeedsReturn($result)
            || $block->callResultFeedsEcho($result)
            || $block->callResultFeedsErrorSuppressExit($result)
            || (null !== $block->orig && $block->orig instanceof ErrorSuppressBlock)
            || $this->isVarExportReturnTrueCall($cfgCallOp, $block)
            || 'iterator_to_array' === $this->resolveCfgFuncCallName($cfgCallOp)
        ) {
            return true;
        }

        return $this->callResultFeedsInlineCallArg($result, $block);
    }



    /**
     * php-cfg evaluates inline array elements before ternary JUMPIFs; rematerialize at INIT_ARRAY (#14134).
     *
     * @return array{0: list<OpCode>, 1: int}
     */
    private function compileDeferredArrayLiteralElementValue(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex,
        bool $forRefBinding = false,
    ): array {
        $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if ([] !== $prefetchOps) {
            return [$prefetchOps, $prefetchOps[0]->arg1];
        }

        $folded = $this->tryFoldArrayElementCompileTimeValue($valueOperand, $block, $arrayExpr, $elementIndex);
        if (null !== $folded) {
            return [[], $folded];
        }

        $valueOperand = $arrayExpr->values[$elementIndex] ?? $valueOperand;
        $producer = $this->findCfgProducerExprForOperand($valueOperand);
        if ($producer instanceof Op\Expr) {
            $ops = $this->rematerializeCfgProducerExprOps($producer, $block);
            if ([] !== $ops) {
                $valueSlot = $this->compileArrayLiteralElementExpressionSlot(
                    $valueOperand,
                    $block,
                    $forRefBinding
                );
                $snapshotOperand = new Operand\Temporary();
                $snapshotSlot = $block->getVarSlot($snapshotOperand, false);
                $ops[] = new OpCode(OpCode::TYPE_ASSIGN, $snapshotSlot, $snapshotSlot, $valueSlot);

                return [$ops, $snapshotSlot];
            }
        }

        return [[], $this->compileArrayLiteralElementExpressionSlot(
            $valueOperand,
            $block,
            $forRefBinding
        )];
    }

    /**
     * Resolve a non-ref array-literal element to its expression-result slot.
     *
     * Zend evaluates elements left-to-right and packs the expression value. Do not rewrite
     * assign.result → lvalue via {@see finalizeOperandSlotForAccess()}: dim/property write
     * slots stay live aliases, so later elements (e.g. array_shift) would mutate the packed
     * value (#23979). By-ref elements still need the live lvalue.
     */
    private function compileArrayLiteralElementExpressionSlot(
        Operand $valueOperand,
        Block $block,
        bool $forRefBinding = false,
    ): int {
        if ($forRefBinding) {
            return (int) $this->compileOperand($valueOperand, $block, false);
        }
        if ($valueOperand instanceof Operand\Temporary || $valueOperand instanceof Operand\Variable) {
            $catchSlot = $this->slotForActiveCatchVariable($valueOperand);
            if (null !== $catchSlot) {
                return $catchSlot;
            }

            return $block->getVarSlot($valueOperand, true);
        }

        return (int) $this->compileOperand($valueOperand, $block, true);
    }

    /**
     * @return list<OpCode>
     */
}
