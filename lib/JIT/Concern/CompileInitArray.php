<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;

/**
 * Array literal init / element / spread opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_INIT_ARRAY},
 * {@code TYPE_ADD_ARRAY_ELEMENT}, {@code TYPE_ARRAY_SPREAD}. Move-only; no IR
 * shape change.
 *
 * php-src: Zend/zend_vm_def.h (ZEND_INIT_ARRAY / ZEND_ADD_ARRAY_ELEMENT /
 * ZEND_ADD_ARRAY_UNPACK), Zend/zend_hash.c — move-only Concern extract; no new
 * C ABI.
 */
trait CompileInitArray
{
    private function compileInitArrayFamilyOp(Block $block, OpCode $op, int $i): void
    {
        switch ($op->type) {
            case OpCode::TYPE_INIT_ARRAY:
                $resultOp = $block->getOperand($op->arg1);
                $initTempOp = $resultOp;
                $nextOp = $block->opCodes[$i + 1] ?? null;
                if (
                    null !== $op->arg1
                    && isset($this->context->coalesceMergeSlotOperands[(int) $op->arg1])
                ) {
                    // True-arm INIT_ARRAY directly at the FUNCCALL ARG_SEND phi slot (#34956 / #34944).
                    $phiOp = $this->context->coalesceMergeSlotOperands[(int) $op->arg1];
                    if (!$this->context->hasVariableOp($phiOp)) {
                        $this->ensureCoalesceMergeStackSlot($phiOp);
                    }
                    $this->context->setVariableOp(
                        $resultOp,
                        $this->context->getVariableFromOp($phiOp)
                    );
                    $resultOp = $phiOp;
                } elseif (
                    null !== $nextOp
                    && OpCode::TYPE_ASSIGN === $nextOp->type
                    && null !== $nextOp->arg2
                    && null !== $nextOp->arg3
                    && (int) $nextOp->arg1 !== (int) $nextOp->arg2
                    && (int) $nextOp->arg3 === (int) $op->arg1
                    // Require an armed coalesce phi — ordinary `$a = [1]` shares this
                    // opcode shape and must not steal the named local (#35258 / re-#33709).
                    && isset($this->context->coalesceMergeSlotOperands[(int) $nextOp->arg2])
                ) {
                    // Else-arm `['x']`: INIT_ARRAY(temp) then ASSIGN(result, phiAlias, temp).
                    $phiSlot = (int) $nextOp->arg2;
                    $phiOp = $this->context->coalesceMergeSlotOperands[$phiSlot];
                    if ($phiOp instanceof Operand) {
                        if (!$this->context->hasVariableOp($phiOp)) {
                            $this->ensureCoalesceMergeStackSlot($phiOp);
                        }
                        $resultOp = $phiOp;
                    }
                } elseif (
                    null !== $op->arg1
                    && null !== ($trailPhiSlot = $this->initArrayCoalescePhiAfterElementTrail(
                        $block,
                        $i,
                        (int) $op->arg1
                    ))
                ) {
                    // Multi-element true-arm: INIT_ARRAY(temp); ADD_*; ASSIGN(_, phi, temp)
                    // when PROPERTY_FETCH reused the phi slot (#34970). Trail helper already
                    // requires coalesceMergeSlotOperands (#35258).
                    $phiOp = $this->context->coalesceMergeSlotOperands[$trailPhiSlot];
                    if ($phiOp instanceof Operand) {
                        if (!$this->context->hasVariableOp($phiOp)) {
                            $this->ensureCoalesceMergeStackSlot($phiOp);
                        }
                        $this->context->setVariableOp(
                            $resultOp,
                            $this->context->getVariableFromOp($phiOp)
                        );
                        $resultOp = $phiOp;
                    }
                }
                $result = $this->context->getVariableFromOp($resultOp);
                if ($resultOp !== $initTempOp) {
                    // If-arm PROPERTY_FETCH often shares slot indices with else-arm
                    // INIT_ARRAY temps; drop fetch-arm SSA before writing the phi (#34956).
                    $result->objectPropertySlot = null;
                    $result->objectPropertyType = null;
                    $result->objectPropertyReceiver = null;
                    $result->objectPropertyReceiverOp = null;
                    $result->objectPropertyName = null;
                    $result->objectPropertyClassName = null;
                    $result->objectPropertyDnfArms = null;
                    $result->compileTimeArray = null;
                    $result->compileTimeAssoc = null;
                }
                \PHPCompiler\JIT\HashTableHelper::initArray($this->context, $result);
                $result->compileTimeEmptyArrayLiteral = null === $op->arg2;
                // Keep named locals (e.g. `$out = []`) flagged so DateTime New_ sync
                // does not treat them as pending object slots (#34461).
                if ($result->compileTimeEmptyArrayLiteral) {
                    if (Variable::TYPE_VALUE === $result->type) {
                        $result->valueBoxHashtable = true;
                    }
                    $arrayName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                    if (null !== $arrayName && '' !== $arrayName) {
                        $this->context->bindVariableByName(
                            $this->context->resolveRefAliasName($arrayName),
                            $result
                        );
                    }
                }
                if (null !== $op->arg2) {
                    $elementOp = $block->getOperand($op->arg2);
                    // NestedJIT VmPregEngine can emit INIT_ARRAY with a dangling arg2 index (#24115).
                    if (null !== $elementOp) {
                        $element = $this->context->getVariableFromOp($elementOp);
                        $key = $this->jitArrayElementKeyVariable($block, $op->arg3);
                        \PHPCompiler\JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                        $this->registerArrayElementClosureCallProxy($block, $block->getOperand($op->arg1), $op->arg3, $element);
                        $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                    }
                }
                if (null !== $initTempOp && $initTempOp !== $resultOp) {
                    if ($this->context->hasVariableOp($initTempOp)) {
                        $tempVar = $this->context->getVariableFromOp($initTempOp);
                        $tempVar->objectPropertySlot = null;
                        $tempVar->objectPropertyType = null;
                        $tempVar->objectPropertyReceiver = null;
                        $tempVar->objectPropertyReceiverOp = null;
                        $tempVar->objectPropertyName = null;
                        $tempVar->objectPropertyClassName = null;
                        $tempVar->objectPropertyDnfArms = null;
                    }
                    $this->context->setVariableOp($initTempOp, $result);
                }
                if ($resultOp !== $initTempOp && null !== $nextOp && OpCode::TYPE_ASSIGN === $nextOp->type) {
                    $phiSlot = (int) $nextOp->arg2;
                    $this->bindCoalesceMergeSlotVariable($block, $phiSlot, $result);
                }
                break;
            case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                $resultOp = $block->getOperand($op->arg1);
                $elementOp = $block->getOperand($op->arg2);
                // NestedJIT VmPregEngine may omit array element operands (#24115).
                if (null === $resultOp || null === $elementOp) {
                    break;
                }
                $result = $this->context->getVariableFromOp($resultOp);
                $element = $this->context->getVariableFromOp($elementOp);
                $key = $this->jitArrayElementKeyVariable($block, $op->arg3);
                \PHPCompiler\JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                $this->registerArrayElementClosureCallProxy($block, $resultOp, $op->arg3, $element);
                $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                break;
            case OpCode::TYPE_ARRAY_SPREAD:
                $destOp = $block->getOperand($op->arg1);
                $srcOp = $block->getOperand($op->arg2);
                if (null === $destOp || null === $srcOp) {
                    break;
                }
                \PHPCompiler\JIT\HashTableHelper::spreadInto(
                    $this->context,
                    $this->context->getVariableFromOp($destOp),
                    $this->context->getVariableFromOp($srcOp)
                );
                break;
            default:
                throw new \LogicException('compileInitArrayFamilyOp: unexpected opcode '.$op->type);
        }
    }
}
