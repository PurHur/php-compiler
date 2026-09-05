<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * ASSIGN_REF shared-box binding and closure invoke metadata (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code ensureAssignRefSharedValueBox}
 * through {@code bindDimFetchReadResult} (~545 lines) so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c ASSIGN_REF / IS_REFERENCE binding and closure
 * invoke / FCC callables in Zend/zend_closures.c — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change.
 */
trait AssignRefSharedBoxAndClosureInvoke
{
    /**
     * Promote ASSIGN_REF source to a stable `__value__` box and bind the name (#34649).
     */
    private function ensureAssignRefSharedValueBox(
        Variable $srcVar,
        string $srcName,
        Operand $srcOp
    ): Variable {
        if (
            Variable::TYPE_VALUE === $srcVar->type
            && Variable::KIND_VARIABLE === $srcVar->kind
        ) {
            if (null === $srcVar->valueBoxAliasPtr) {
                $srcVar->valueBoxAliasPtr = JIT\JitValueBox::valuePtrFromVariable(
                    $this->context,
                    $srcVar
                );
            }
            $srcVar->assignRefLvalueAlias = true;
            $this->context->bindVariableByName($srcName, $srcVar);
            $this->context->setVariableOp($srcOp, $srcVar);

            return $srcVar;
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $slotPtr = JIT\JitValueBox::pointer($this->context, $slot);
        JIT\JitValueBox::assignToPointer($this->context, $slotPtr, $srcVar);
        JIT\JitValueBox::publishAfterWrite($this->context, $slotPtr);
        $boxed = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $boxed->valueBoxAliasPtr = $slotPtr;
        $boxed->assignRefLvalueAlias = true;
        $this->context->bindVariableByName($srcName, $boxed);
        $this->context->setVariableOp($srcOp, $boxed);

        return $boxed;
    }

    /**
     * Store `$v`'s `__value__*` into the object's property void** (Zend ASSIGN_REF).
     */
    private function pointObjectPropertySlotAtValueBox(Variable $propVar, Variable $boxVar): void
    {
        if (null === $propVar->objectPropertySlot) {
            return;
        }
        $boxPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $boxVar);
        $slot = JIT\Builtin\Type\ObjectInstancePropertyLlvm::dominatingSlotPtr(
            $this->context->type->object,
            $propVar
        );
        $voidPtr = $this->context->getTypeFromString('void*');
        $this->context->builder->store(
            $this->context->builder->pointerCast($boxPtr, $voidPtr),
            $slot
        );
    }

    /**
     * `$a[] =& $x` / `$a[0] =& $x`: copy the shared box into the HT entry and register it for
     * write-through (#34685 / #34689).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function syncAssignRefDimEntryFromShared(Variable $destVar, Variable $shared): bool
    {
        $entryPtr = $this->assignRefDestEntryPointer($destVar);
        if (null === $entryPtr) {
            return false;
        }
        $sharedPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $shared);
        JIT\JitValueBox::copyIntoPointer($this->context, $entryPtr, $sharedPtr);
        if (null === $shared->assignRefSyncEntryPtrs) {
            $shared->assignRefSyncEntryPtrs = [];
        }
        $shared->assignRefSyncEntryPtrs[] = $entryPtr;
        $this->markAssignRefLvalueAlias($shared);

        return true;
    }

    /**
     * After `$x = …` on a multi-append ASSIGN_REF shared box, refresh every HT alias (#34685).
     */
    private function syncAssignRefHtEntriesFromShared(Variable $shared): void
    {
        if (null === $shared->assignRefSyncEntryPtrs || [] === $shared->assignRefSyncEntryPtrs) {
            return;
        }
        $sharedPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $shared);
        foreach ($shared->assignRefSyncEntryPtrs as $entryPtr) {
            JIT\JitValueBox::copyIntoPointer($this->context, $entryPtr, $sharedPtr);
        }
    }

    /**
     * Mark ASSIGN_REF lvalue so #34465 does not strip objectPropertySlot on `$v = …` (#34649).
     */
    private function markAssignRefLvalueAlias(Variable $var): void
    {
        if (
            null !== $var->objectPropertySlot
            || null !== $var->staticPropertyGlobal
            || null !== $var->writableHt
            || null !== $var->valueBoxAliasPtr
            || (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            )
        ) {
            $var->assignRefLvalueAlias = true;
        }
    }

    /**
     * True when ASSIGN_REF dest is a FETCH_DIM_W / []= / property / static lvalue (#34645).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function isAssignRefWritableDest(Variable $var): bool
    {
        if (null !== $var->objectPropertySlot || null !== $var->staticPropertyGlobal) {
            return true;
        }
        if (null !== $var->writableHt) {
            return null !== $var->writableIndex
                || null !== $var->writableStringKey
                || null !== $var->writableObjectKey
                || null !== $var->writableValueBoxKey;
        }
        if (null !== $var->writableArrayAccessReceiver && null !== $var->writableArrayAccessKey) {
            return true;
        }
        // reserveAppendSlot: KIND_VARIABLE pointing at the HT entry (no writableHt markers).
        return Variable::TYPE_VALUE === $var->type
            && Variable::KIND_VARIABLE === $var->kind
            && null === $var->valueBoxAliasPtr
            && !$var->functionStaticGlobal
            && null === $var->objectPropertySlot
            && null === $var->staticPropertyGlobal;
    }

    /**
     * `$a[] = &$o->p`: after the value is copied into the dim entry, redirect the source
     * lvalue onto that entry so later property/static writes update the array (#5349).
     */
    private function aliasAssignRefDestOntoSourceStorage(Variable $destVar, Variable $srcVar): void
    {
        $entryPtr = $this->assignRefDestEntryPointer($destVar);
        if (null === $entryPtr) {
            return;
        }
        if (null !== $srcVar->objectPropertySlot) {
            $voidPtr = $this->context->getTypeFromString('void*');
            $this->context->builder->store(
                $this->context->builder->pointerCast($entryPtr, $voidPtr),
                $srcVar->objectPropertySlot
            );
            if (Variable::TYPE_VALUE === $srcVar->type) {
                $srcVar->value = $entryPtr;
            }

            return;
        }
        if (null !== $srcVar->staticPropertyGlobal) {
            $srcVar->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
            if (Variable::TYPE_VALUE === $srcVar->type && Variable::KIND_VARIABLE === $srcVar->kind) {
                $srcVar->value = $entryPtr;
            }

            return;
        }
        if (null !== $srcVar->valueBoxAliasPtr) {
            // Named locals already rebound; leftover aliases (dim→dim) share the entry box.
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $entryPtr,
                JIT\JitValueBox::normalizeValuePtr($this->context, $srcVar->valueBoxAliasPtr)
            );
            $srcVar->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
        }
    }

    /**
     * `$r = &$a[0]` / `[&$x] = $a`: point the named dest at the live HT entry (Zend IS_REFERENCE).
     *
     * FETCH_DIM_W orphan boxes are empty until hydrated; ref binds must alias the slot (#34673).
     *
     * @see php-src Zend/zend_execute.c zend_assign_to_variable_reference
     */
    private function aliasAssignRefNamedDestToDimEntry(Variable $dimLvalue): void
    {
        $entryPtr = $this->assignRefDestEntryPointer($dimLvalue);
        if (null === $entryPtr) {
            return;
        }
        $dimLvalue->valueBoxAliasPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $entryPtr);
        $dimLvalue->borrowedValueEntry = true;
    }

    /**
     * Live `__value__*` for an ASSIGN_REF dim dest (append entry or packed writableHt).
     *
     * Packed `$a[0]=&$x` on an empty HT must materialise the slot first — a bare GEP into
     * `values` does not bump `used`, so later `$x=9` never appears in the array (#34689).
     *
     * @see php-src Zend/zend_execute.c zend_fetch_dimension_address / zend_assign_to_variable_reference
     * @see JIT\HashTableWriteLlvm::hydrateIndexWriteLvalue
     */
    private function assignRefDestEntryPointer(Variable $destVar): ?\PHPLLVM\Value
    {
        // Packed index + string-key FETCH_DIM_W orphans (#34740 / #34673 / #34689).
        $dimEntry = JIT\HashTableWriteLlvm::liveEntryPointerForWritableDim($this->context, $destVar);
        if (null !== $dimEntry) {
            return $dimEntry;
        }
        if (
            Variable::TYPE_VALUE === $destVar->type
            && Variable::KIND_VARIABLE === $destVar->kind
            && null === $destVar->writableHt
            && null === $destVar->objectPropertySlot
            && null === $destVar->staticPropertyGlobal
        ) {
            return JIT\JitValueBox::valuePtrFromVariable($this->context, $destVar);
        }

        return null;
    }

    /**
     * Record Closure invoke proxy for a compile-time array literal element (#24106 peer).
     *
     * foreach ($arr as $k => $fn) loses Variable::closureCall on $fn when the value is
     * loaded from a hashtable slot — RuntimeIndirectClosureCall then skips pending-throw
     * catch wiring and TypeError inside the closure SIGABRTs under AOT (#33971 peer).
     */
    private function registerArrayElementClosureCallProxy(
        Block $block,
        Operand $arrayResultOp,
        ?int $keyArg,
        Variable $element
    ): void {
        if (null === $element->closureCall) {
            return;
        }
        $arraySlot = $block->slotForOperand($arrayResultOp);
        if (null === $arraySlot) {
            return;
        }
        $keyLabel = $this->compileTimeArrayElementKeyLabel($block, $keyArg);
        if (null === $keyLabel) {
            return;
        }
        $this->context->closureCallByArrayResultSlot[$arraySlot][$keyLabel] = $element->closureCall;
        $this->context->closureCallOrderedByArrayResultSlot[$arraySlot][] = $element->closureCall;
    }

    /**
     * Restore ClosureWithCaptures on foreach value locals when the container was a literal (#24106).
     */
    /**
     * Foreach iter closures: runtime index dispatch into literal build-order table (#34240).
     */
    private function reattachForeachIterClosureInvokeMetadata(
        Block $block,
        Operand $arrayOp,
        Operand $destOp,
        Variable $value
    ): void {
        $result = $this->context->getVariableFromOp($destOp);
        $this->preserveClosureInvokeMetadata($destOp, $result, $value);
        $result->closureCall = null;
        $result->closureIsStatic = false;
        $result->closureIsMethodFake = false;
        $destSlot = $block->slotForOperand($destOp);
        if (null !== $destSlot) {
            unset($this->context->fccClosureCallByResultSlot[$destSlot]);
        }
        $arraySlot = $block->slotForOperand($arrayOp);
        if (null === $arraySlot) {
            $result->foreachClosureProxyTable = null;
            $result->foreachContainerSlotKey = null;

            return;
        }
        $table = $this->context->closureCallOrderedByArrayResultSlot[$arraySlot] ?? [];
        if ([] === $table) {
            $result->foreachClosureProxyTable = null;
            $result->foreachContainerSlotKey = null;

            return;
        }
        $arrayVar = $this->context->getVariableFromOp($arrayOp);
        $containerKey = $this->context->foreachSlotMapKey($arrayVar);
        if (!isset($this->context->foreachIndexSlots[$containerKey])) {
            throw new \LogicException(
                'foreach closure dispatch: missing index slot for container key '.$containerKey
            );
        }
        $result->foreachClosureProxyTable = $table;
        $result->foreachContainerSlotKey = $containerKey;
        $resolved = JIT\OperandName::resolve($destOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $result);
        }
    }

    private function compileTimeArrayElementKeyLabel(Block $block, ?int $keyArg): ?string
    {
        if (null === $keyArg) {
            return null;
        }
        $intKey = $this->tryCompileTimeArrayLiteralIntKey($block, $keyArg);
        if (null !== $intKey) {
            return (string) $intKey;
        }
        $op = $block->getOperand($keyArg);
        if ($op instanceof Operand\Literal) {
            if (is_string($op->value)) {
                return $op->value;
            }
            if (is_int($op->value)) {
                return (string) $op->value;
            }
        }
        if (isset($block->constants[$keyArg])) {
            $const = $block->constants[$keyArg];
            if (VM\Variable::TYPE_STRING === $const->type) {
                return $const->toString();
            }
            if (VM\Variable::TYPE_INTEGER === $const->type) {
                return (string) $const->toInt();
            }
        }
        $keyVar = $this->jitArrayElementKeyVariable($block, $keyArg);

        return $this->normalizeArrayElementKeyLabel($keyVar);
    }

    private function normalizeArrayElementKeyLabel(?Variable $key): ?string
    {
        if (null === $key) {
            return null;
        }
        if (null !== $key->compileTimeString) {
            return $key->compileTimeString;
        }

        return null;
    }

    /** Keep Closure invoke proxy across assigns into locals / value boxes (#24106, #23973). */
    private function preserveClosureInvokeMetadata(Operand $resultOp, Variable $result, Variable $value): void
    {
        if (null !== $value->foreachClosureProxyTable && [] !== $value->foreachClosureProxyTable) {
            $result->foreachClosureProxyTable = $value->foreachClosureProxyTable;
            $result->foreachContainerSlotKey = $value->foreachContainerSlotKey;
        }
        if (null === $value->closureCall) {
            if (null !== $value->foreachClosureProxyTable && [] !== $value->foreachClosureProxyTable) {
                $resolved = JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $result);
                }
            }

            return;
        }
        // FCC `$b = $obj->m(...)` is CFG-typed as array, so `$b` starts as a hashtable.
        // Stamping closureCall onto that HT leaves `$b()` aborting under AOT while
        // `((new C)->m(...))(3)` (temp, still object) works (#28613, peer #24106).
        if (Variable::TYPE_OBJECT === $value->type && Variable::TYPE_OBJECT !== $result->type) {
            $this->context->setVariableOp($resultOp, $value);
            $result = $value;
        }
        $result->closureCall = $value->closureCall;
        $result->closureIsStatic = $value->closureIsStatic;
        $result->closureIsMethodFake = $value->closureIsMethodFake;
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $result);
        }
        if (null !== $this->context->jitCurrentBlock) {
            $slot = $this->context->jitCurrentBlock->slotForOperand($resultOp);
            if (null !== $slot) {
                $this->context->fccClosureCallByResultSlot[$slot] = $value->closureCall;
            }
        }
    }

    /**
     * Stash Closure invoke proxy when a user function/method returns a known closure (#34868).
     *
     * Cross-function `$f = m(); $f()` cannot see the callee Variable::closureCall; without this
     * map EXEC_RETURN leaves a bare object and FUNCCALL_INIT falls through to a null callee.
     */
    private function recordFunctionReturnedClosureCall(Block $block, Variable $return): void
    {
        if (null === $return->closureCall || null === $block->func) {
            return;
        }
        $funcName = $block->func->name ?? null;
        if (!is_string($funcName) || '' === $funcName || '{main}' === $funcName) {
            return;
        }
        $lc = strtolower($funcName);
        if (null !== $block->func->class && is_string($block->func->class->value ?? null)) {
            $classLc = strtolower(ltrim((string) $block->func->class->value, '\\'));
            if ('' !== $classLc) {
                $lc = $classLc.'::'.$lc;
            }
        }
        $this->context->functionReturnedClosureCall[$lc] = $return->closureCall;
    }

    /**
     * Reattach callee-returned Closure invoke metadata onto EXEC_RETURN's result (#34868).
     */
    private function attachReturnedClosureInvokeMetadata(Block $block, OpCode $op): void
    {
        $toCall = $this->context->scope->toCall;
        $lc = null;
        if ($toCall instanceof JIT\Call\Native) {
            $lc = strtolower($toCall->name);
        } elseif ($toCall instanceof CoreFunc\Internal) {
            $lc = strtolower($toCall->getName());
        }
        if (null === $lc || !isset($this->context->functionReturnedClosureCall[$lc])) {
            return;
        }
        $proxy = $this->context->functionReturnedClosureCall[$lc];
        $resultOp = $block->getOperand($op->arg1);
        if (null === $resultOp || !$this->context->hasVariableOp($resultOp)) {
            return;
        }
        $var = $this->context->getVariableFromOp($resultOp);
        // Caller-frame result Variable — safe to use as closureObject for heap reload (#35456).
        if ($proxy instanceof JIT\Call\ClosureWithBinding) {
            $proxy = $proxy->withClosureObject($var);
        }
        $var->closureCall = $proxy;
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName($resolved, $var);
        }
        $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $proxy;
        $slot = $block->slotForOperand($resultOp);
        if (null !== $slot) {
            $this->context->fccClosureCallByResultSlot[$slot] = $proxy;
        }
    }

    /**
     * Closure::bind / bindTo boxReturn() drops Variable::closureCall; reattach the
     * ClosureWithBinding stashed on lastClosureCallProxy so `$b()` / immediate
     * invoke use bound $this + scope instead of RuntimeIndirect abort (#27219).
     */
    private function attachBoundClosureInvokeMetadata(Block $block, OpCode $op): void
    {
        $toCall = $this->context->scope->toCall;
        if (
            !($toCall instanceof JIT\Call\ClosureBindTo)
            && !($toCall instanceof JIT\Call\ClosureBind)
        ) {
            return;
        }
        $proxy = $this->context->lastClosureCallProxy;
        if (!($proxy instanceof JIT\Call\ClosureWithBinding)) {
            return;
        }
        $resultOp = $block->getOperand($op->arg1);
        if ($this->context->hasVariableOp($resultOp)) {
            $var = $this->context->getVariableFromOp($resultOp);
            $var->closureCall = $proxy;
            $resolved = JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $var);
            }
        }
        $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $proxy;
    }

    /**
     * Recover FCC invoke proxy when temp/local metadata was dropped before FUNCCALL_INIT (#24166).
     */
    private function resolveFccClosureCallForCalleeSlot(Block $block, int $nameSlot, array &$visited = []): ?JIT\Call
    {
        if (isset($visited[$nameSlot])) {
            return null;
        }
        $visited[$nameSlot] = true;
        if (isset($this->context->fccClosureCallByResultSlot[$nameSlot])) {
            return $this->context->fccClosureCallByResultSlot[$nameSlot];
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type || (int) $prior->arg2 !== $nameSlot) {
                if (OpCode::TYPE_ITER_VALUE === $prior->type && (int) $prior->arg1 === $nameSlot) {
                    $destOp = $block->getOperand($prior->arg1);
                    if (null !== $destOp && $this->context->hasVariableOp($destOp)) {
                        $var = $this->context->getVariableFromOp($destOp);
                        if (null !== $var->closureCall) {
                            return $var->closureCall;
                        }
                        $table = $var->foreachClosureProxyTable ?? null;
                        $slotKey = $var->foreachContainerSlotKey ?? null;
                        if (null !== $table && [] !== $table && is_string($slotKey) && '' !== $slotKey) {
                            return new JIT\Call\ForeachIndexedClosureCall($var, $table, $slotKey);
                        }
                    }
                }
                continue;
            }
            $resolved = $this->resolveFccClosureCallForCalleeSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private function bindDimFetchReadResult(Operand $resultOp, Variable $fetched, bool $forceBranchMerge): void
    {
        if (
            Variable::TYPE_VALUE === $fetched->type
            && $this->context->hasVariableOp($resultOp)
            && Variable::TYPE_OBJECT === $this->context->getVariableFromOp($resultOp)->type
        ) {
            // Object-typed temps from fromOp are uninitialized __object__** slots — bind the
            // HT value box directly so chained ->prop uses __value__readObject (#31938).
            $this->context->setVariableOp($resultOp, $fetched);
            $fetched->addref();

            return;
        }
        if ($forceBranchMerge) {
            $this->assignOperand($resultOp, $fetched, true);
        } else {
            $this->assignOperand($resultOp, $fetched);
        }
    }
}
