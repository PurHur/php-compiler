<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;

/**
 * POW opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_POW}.
 * Wrapped in {@code switch (true)} so original case-level {@code break}
 * semantics are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_POW / ZEND_ASSIGN_POW),
 * Zend/zend_operators.c (pow_function) — move-only Concern extract;
 * no new C ABI.
 */
trait CompilePow
{
    private function compilePowOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    $powLeftOp = $block->getOperand($op->arg2);
                    $powDestOp = $block->getOperand($op->arg1);
                    if (
                        (
                            $this->context->hasVariableOp($powLeftOp)
                            && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue(
                                $this->context->getVariableFromOp($powLeftOp),
                                $this->context
                            )
                        )
                        || (
                            $this->context->hasVariableOp($powDestOp)
                            && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue(
                                $this->context->getVariableFromOp($powDestOp),
                                $this->context
                            )
                        )
                    ) {
                        \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                        break;
                    }
                    $powLeft = $this->variableFromOpForRuntimeRead($block->getOperand($op->arg2));
                    // FETCH_DIM_W orphan — ZEND_ASSIGN_DIM_OP for **= (#32798 / leftover #32789).
                    \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $powLeft);
                    $powRight = $this->variableFromOpForRuntimeRead($block->getOperand($op->arg3));
                    $powDestOp = $block->getOperand($op->arg1);
                    $powDest = $this->context->getVariableFromOp($powDestOp);
                    // In-place `$r **= n` after `$r =& $obj->prop`: php-cfg dest is a dead
                    // Temporary; assignOperandValue never propertyStore's (leftover of #35964).
                    $powProp = $powDest;
                    if (
                        (null === $powProp->objectPropertySlot || null === $powProp->objectPropertyType)
                        && null !== $powLeft->objectPropertySlot
                        && null !== $powLeft->objectPropertyType
                    ) {
                        $powProp = $powLeft;
                    }
                    $inPlacePow = (int) $op->arg1 === (int) $op->arg2;
                    if (
                        null !== $powProp->objectPropertySlot
                        && null !== $powProp->objectPropertyType
                        && $inPlacePow
                    ) {
                        $this->context->setVariableOp($powDestOp, $powProp);
                        $this->compileObjectPropertyPowOp($powProp, $powLeft, $powRight);
                        break;
                    }
                    $pow = new \PHPCompiler\ext\standard\pow();
                    $this->context->powReturnValueBox = true;
                    $powResult = $pow->call(
                        $this->context,
                        $powLeft,
                        $powRight
                    );
                    $this->context->powReturnValueBox = false;
                    // `$r =& $a[$k]` / `$r =& $obj->prop[$k]; $r **= n`: dead dest lacks
                    // writableHt / assignRefLvalueAlias; left was hydrated above. Rebind and
                    // assignOperand (same as TYPE_MUL) so the shared HT entry updates (#35984).
                    // assignOperandValue reseats typed dests onto a fresh alloca and orphans
                    // the by-ref dim box — leaving both $r and $a[$k] at the old value.
                    if (
                        (
                            null === $powDest->writableHt
                            && null !== $powLeft->writableHt
                        )
                        || (
                            !$powDest->assignRefLvalueAlias
                            && $powLeft->assignRefLvalueAlias
                        )
                        || (
                            null === $powDest->valueBoxAliasPtr
                            && null !== $powLeft->valueBoxAliasPtr
                            && null === $powLeft->objectPropertySlot
                        )
                    ) {
                        $this->context->setVariableOp($powDestOp, $powLeft);
                        $powDest = $powLeft;
                    }
                    $this->assignOperand(
                        $powDestOp,
                        new Variable(
                            $this->context,
                            Variable::TYPE_VALUE,
                            Variable::KIND_VALUE,
                            $powResult
                        ),
                        true
                    );
                    if (null !== $powDest->writableHt) {
                        \PHPCompiler\JIT\HashTableHelper::commitDimWriteLvalue($this->context, $powDest);
                    }
                    break;
        }
    }
}
