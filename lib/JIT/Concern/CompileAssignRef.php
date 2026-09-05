<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;

/**
 * ASSIGN_REF opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ASSIGN_REF}.
 * Wrapped in {@code switch (true)} so original case-level {@code break}
 * semantics are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_ASSIGN_REF / ZEND_ASSIGN_OBJ_REF /
 * ZEND_ASSIGN_DIM_REF), Zend/zend_execute.c
 * (zend_assign_to_variable_reference) — move-only Concern extract; no new C ABI.
 */
trait CompileAssignRef
{
    private function compileAssignRefOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    if (null !== $op->arg3 && 1 === (int) $op->arg3) {
                        throw new \LogicException('Cannot assign reference to non referenceable value');
                    }
                    if (
                        null !== $op->arg3
                        && OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK === (int) $op->arg3
                    ) {
                        break;
                    }
                    $destOp = $block->getOperand($op->arg1);
                    $srcOp = $block->getOperand($op->arg2);
                    // Zend: cannot create references to/from string offsets (#21910).
                    if ($this->context->hasVariableOp($destOp)) {
                        $destProbe = $this->context->getVariableFromOp($destOp);
                        if (\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($destProbe, $this->context)) {
                            \PHPCompiler\JIT\StringOffsetHelper::emitRefError($this->context);
                            $this->context->builder->call($this->context->lookupFunction('abort'));
                            $this->context->builder->clearInsertionPosition();
                            break;
                        }
                    }
                    if ($this->context->hasVariableOp($srcOp)) {
                        $srcProbe = $this->context->getVariableFromOp($srcOp);
                        if (\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($srcProbe, $this->context)) {
                            \PHPCompiler\JIT\StringOffsetHelper::emitRefError($this->context);
                            $this->context->builder->call($this->context->lookupFunction('abort'));
                            $this->context->builder->clearInsertionPosition();
                            break;
                        }
                    }
                    $destName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                    $srcName = \PHPCompiler\JIT\OperandName::resolve($srcOp);
                    // Unnamed dest: FETCH_DIM_W / []= / property / static (#34645, re-#5349).
                    // Inverted `$r = &$a[0]` (#32669): store into the lvalue then rebind the
                    // named source onto that lvalue so later `$x = 9` commits into the HT/prop.
                    if (null === $destName) {
                        if (
                            !$this->context->hasVariableOp($destOp)
                            || !$this->isAssignRefWritableDest(
                                $this->context->getVariableFromOp($destOp)
                            )
                        ) {
                            throw new \LogicException('Reference assignment requires named destination variable');
                        }
                        $destVar = $this->context->getVariableFromOp($destOp);
                        if (
                            $this->context->hasVariableOp($srcOp)
                            && !\PHPCompiler\JIT\JitReferencableCheck::isOperandReferenceable(
                                $srcOp,
                                $this->context->getVariableFromOp($srcOp)
                            )
                        ) {
                            \PHPCompiler\JIT\JitReferencableCheck::emitNonVariableAssignRefNotice($this->context);
                            $this->assignOperand($destOp, $this->context->getVariableFromOp($srcOp));
                            break;
                        }
                        if (!$this->context->hasVariableOp($srcOp)) {
                            throw new \LogicException('Reference assignment requires a bound source variable');
                        }
                        $srcVar = $this->context->getVariableFromOp($srcOp);
                        \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeByRef($this->context, $srcVar);
                        \PHPCompiler\JIT\ReadonlyClassGuard::emitBeforePropertyByRef($this->context, $srcVar, $this);
                        // `$o->p =& $v`: point the property slot at $v's value box (Zend IS_REFERENCE).
                        // Do not rebind $v onto the fetch temp — its alloca is not the heap box
                        // propertyStore writes (#34649 / re-#5370).
                        if (null !== $destVar->objectPropertySlot && null !== $srcName) {
                            $shared = $this->ensureAssignRefSharedValueBox($srcVar, $srcName, $srcOp);
                            $this->pointObjectPropertySlotAtValueBox($destVar, $shared);
                            break;
                        }
                        // `$a[] =& $x` / `$a[$k] =& $x`: keep $x on a stable shared box and sync
                        // each HT entry from it. Rebinding $x onto the append slot (old #34645)
                        // only works for one append — a second `$a[] =& $x` left the first slot
                        // as a stale copy (#34685 / Zend zend_assign_to_variable_reference).
                        if (null !== $srcName) {
                            $shared = $this->ensureAssignRefSharedValueBox($srcVar, $srcName, $srcOp);
                            if ($this->syncAssignRefDimEntryFromShared($destVar, $shared)) {
                                break;
                            }
                            // String-key / exotic dim without a live entry pointer: fall through
                            // to copy+rebind (single-alias behaviour, #34645).
                            $srcVar = $shared;
                        }
                        if (
                            Variable::TYPE_VALUE === $srcVar->type
                            && null === $srcVar->valueBoxAliasPtr
                            && !$srcVar->borrowedValueEntry
                            && null === $srcVar->writableHt
                            && null === $srcVar->objectPropertySlot
                            && null === $srcVar->staticPropertyGlobal
                        ) {
                            $srcVar->valueBoxAliasPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable(
                                $this->context,
                                $srcVar
                            );
                        }
                        $this->assignOperand($destOp, $srcVar);
                        $destVar = $this->context->getVariableFromOp($destOp);
                        $destVar->assignRefLvalueAlias = true;
                        if (null !== $srcName) {
                            $this->markAssignRefLvalueAlias($destVar);
                            $this->context->bindVariableByName($srcName, $destVar);
                            $this->context->setVariableOp($srcOp, $destVar);
                        } elseif (
                            null !== $srcVar->objectPropertySlot
                            || null !== $srcVar->staticPropertyGlobal
                            || null !== $srcVar->writableHt
                            || null !== $srcVar->valueBoxAliasPtr
                        ) {
                            // `$a[] = &$o->p` / `$a[] = &$a[0]`: leave src as canonical
                            // storage and point the dim entry at that box (#5349 compliance).
                            $this->aliasAssignRefDestOntoSourceStorage($destVar, $srcVar);
                            $this->markAssignRefLvalueAlias($srcVar);
                        }
                        break;
                    }
                    // Zend: `$a =& f()` / non-variable RHS → Notice + value assign (#30015).
                    // Class static properties are lvalues (FETCH_STATIC_PROP_W, #32036).
                    if (
                        $this->context->hasVariableOp($srcOp)
                        && !\PHPCompiler\JIT\JitReferencableCheck::isOperandReferenceable(
                            $srcOp,
                            $this->context->getVariableFromOp($srcOp)
                        )
                    ) {
                        \PHPCompiler\JIT\JitReferencableCheck::emitNonVariableAssignRefNotice($this->context);
                        $this->assignOperand($destOp, $this->context->getVariableFromOp($srcOp));
                        break;
                    }
                    if (null === $srcName) {
                        $this->context->foreachByRefLocalNames[$this->context->resolveRefAliasName($destName)] = true;
                    }
                    if (null !== $srcName) {
                        if ($this->context->hasVariableOp($srcOp)) {
                            $srcVar = $this->context->getVariableFromOp($srcOp);
                            \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeByRef($this->context, $srcVar);
                            \PHPCompiler\JIT\ReadonlyClassGuard::emitBeforePropertyByRef($this->context, $srcVar, $this);
                            if (
                                Variable::TYPE_VALUE === $srcVar->type
                                && null === $srcVar->valueBoxAliasPtr
                                && !$srcVar->borrowedValueEntry
                                && null === $srcVar->writableHt
                            ) {
                                $srcVar->valueBoxAliasPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable(
                                    $this->context,
                                    $srcVar
                                );
                            }
                            $this->markAssignRefLvalueAlias($srcVar);
                            $this->context->bindVariableByName($destName, $srcVar);
                            $this->context->setVariableOp($destOp, $srcVar);
                            break;
                        }
                        $this->context->refAliasNames[$destName] = $this->context->resolveRefAliasName($srcName);
                        break;
                    }
                    if (!$this->context->hasVariableOp($srcOp)) {
                        throw new \LogicException('Reference assignment requires a bound source variable');
                    }
                    $srcVar = $this->context->getVariableFromOp($srcOp);
                    \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeByRef($this->context, $srcVar);
                    \PHPCompiler\JIT\ReadonlyClassGuard::emitBeforePropertyByRef($this->context, $srcVar, $this);
                    $this->aliasAssignRefNamedDestToDimEntry($srcVar);
                    if (
                        Variable::TYPE_VALUE === $srcVar->type
                        && null === $srcVar->valueBoxAliasPtr
                        && !$srcVar->borrowedValueEntry
                        && null === $srcVar->writableHt
                        // Live property/static slots must not get a by-value copy pointer (#35898).
                        && null === $srcVar->objectPropertySlot
                        && null === $srcVar->staticPropertyGlobal
                    ) {
                        $srcVar->valueBoxAliasPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable(
                            $this->context,
                            $srcVar
                        );
                    }
                    // `$r = &$o->p` / `$r = &$a[0]`: named dest aliases the lvalue (#34649).
                    $this->markAssignRefLvalueAlias($srcVar);
                    $this->context->bindVariableByName($destName, $srcVar);
                    $this->context->setVariableOp($destOp, $srcVar);
                    \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $srcVar);
                    break;
        }
    }
}
