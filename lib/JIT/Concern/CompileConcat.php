<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * CONCAT opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_CONCAT}. Wrapped in
 * {@code switch (true)} so original case-level {@code break} semantics are
 * preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_CONCAT / ZEND_FAST_CONCAT), Zend/zend_operators.c
 * (concat_function / zend_string_concat) — move-only Concern extract; no new C ABI.
 */
trait CompileConcat
{
    private function compileConcatOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func
    ): void {
        switch (true) {
            case true:
                if (null === $op->arg2 || null === $op->arg3) {
                    break;
                }
                $destOp = $block->getOperand($op->arg1);
                if (null === $destOp) {
                    break;
                }
                // `$s .= lit.$i.lit…` — defer single-use concat temps and append leaves
                // into the destination (php-src smart_str / ZEND_ASSIGN_CONCAT) (#36386).
                if ($this->tryCompileConcatChainFlatten($func, $block, $op, $i)) {
                    break;
                }
                // Ternary echo-phi: CONCAT dest is the merge slot. Store into the shared
                // stack phi — do not allocate an ephemeral that ECHO will never read (#33849).
                $concatPhiOp = null;
                $destSlotNum = (int) $op->arg1;
                if (isset($this->context->coalesceMergeSlotOperands[$destSlotNum])) {
                    $concatPhiOp = $this->context->coalesceMergeSlotOperands[$destSlotNum];
                } elseif ($this->context->coalesceAssignTargets->contains($destOp)) {
                    $concatPhiOp = $destOp;
                }
                if (null !== $concatPhiOp) {
                    $leftOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
                    $rightOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3);
                    $left = $this->context->getVariableFromOp($leftOp);
                    $right = $this->context->getVariableFromOp($rightOp);
                    if (\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($left, $this->context)) {
                        \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                        break;
                    }
                    // Property-fetch temps alias the live object slot (objectPropertySlot).
                    // Detach before concat so in-place CONCAT($slot,$slot,lit) cannot empty
                    // DOMElement::$nodeName / similar (#33849).
                    $left = $this->detachObjectPropertyStringForConcat($left);
                    $right = $this->detachObjectPropertyStringForConcat($right);
                    $newVal = $this->compileConcatIntoNewString($left, $right, $leftOp, $rightOp);
                    if (!$this->context->hasVariableOp($concatPhiOp)) {
                        $this->ensureCoalesceMergeStackSlot($concatPhiOp);
                    }
                    $phiVar = $this->context->getVariableFromOp($concatPhiOp);
                    // Phi must stay a stack box — never inherit fetch-arm property SSA.
                    $phiVar->objectPropertySlot = null;
                    $phiVar->objectPropertyType = null;
                    $phiVar->objectPropertyReceiver = null;
                    $phiVar->objectPropertyName = null;
                    $phiVar->objectPropertyClassName = null;
                    $phiVar->objectPropertyDnfArms = null;
                    $phiVar->staticPropertyGlobal = null;
                    $phiVar->staticPropertyType = null;
                    $phiVar->valueBoxAliasPtr = null;
                    // Write the concat string into the phi box directly — assignOperand
                    // would follow a stale objectPropertySlot and empty DOMElement::$nodeName
                    // when php-cfg used in-place CONCAT($fetch,$fetch,lit) (#33849).
                    if (
                        Variable::TYPE_VALUE === $phiVar->type
                        && Variable::KIND_VARIABLE === $phiVar->kind
                    ) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeString'),
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $phiVar),
                            $this->context->helper->loadValue($newVal)
                        );
                        if (null !== ($newVal->compileTimeString ?? null)) {
                            $phiVar->compileTimeString = $newVal->compileTimeString;
                        }
                        $phiVar->isNullConstant = false;
                    } else {
                        $this->assignOperand($concatPhiOp, $newVal, true);
                    }
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                }
                $destIsDeadOperand = false;
                foreach ($block->orig->deadOperands ?? [] as $deadOp) {
                    if ($deadOp === $destOp) {
                        $destIsDeadOperand = true;
                        break;
                    }
                }
                // php-cfg leaves Concat.result as a dead Temporary before FuncCall/Echo;
                // prologue may already have allocated the scope slot, but in-place CONCAT into
                // a slot that aliases the left operand corrupts the local (#23798).
                if ($destIsDeadOperand) {
                    $leftOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
                    $rightOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3);
                    $left = $this->context->getVariableFromOp($leftOp);
                    $right = $this->context->getVariableFromOp($rightOp);
                    if (\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($left, $this->context)) {
                        \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                        break;
                    }
                    // In-place `$a[i].= …`: dest is CFG-dead (echo re-fetches) but must still
                    // commit into the FETCH_DIM_W hashtable (#32798 / ZEND_ASSIGN_DIM_OP).
                    if (
                        (int) $op->arg1 === (int) $op->arg2
                        && null !== $left->writableHt
                    ) {
                        \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $left);
                        $newVal = $this->compileConcatIntoNewString(
                            $left,
                            $right,
                            $leftOp,
                            $rightOp
                        );
                        $this->assignOperand($destOp, $newVal, true);
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        break;
                    }
                    // In-place `$s .= …` on a function-static: php-cfg may mark the
                    // static CV dead even when RETURN still reads it. Ephemeral alloca
                    // concat then never writes the module box (#32889 / leftover #31966).
                    if (
                        (int) $op->arg1 === (int) $op->arg2
                        && $left->functionStaticGlobal
                    ) {
                        $newVal = $this->compileConcatIntoNewString(
                            $left,
                            $right,
                            $leftOp,
                            $rightOp
                        );
                        \PHPCompiler\JIT\JitValueBox::assignToPointer(
                            $this->context,
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $left),
                            $newVal
                        );
                        \PHPCompiler\JIT\JitValueBox::publishAfterWrite(
                            $this->context,
                            \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $left)
                        );
                        $this->context->setVariableOp($destOp, $left);
                        if (null !== ($newVal->compileTimeString ?? null)) {
                            $left->compileTimeString = $newVal->compileTimeString;
                        }
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        break;
                    }
                    // In-place `C::$s .= …`: FETCH + dead CONCAT into the fetch temp — same
                    // ephemeral trap; must store via staticPropertyGlobal (#32899 / peer #32889).
                    if (
                        (int) $op->arg1 === (int) $op->arg2
                        && null !== $left->staticPropertyGlobal
                    ) {
                        $newVal = $this->compileConcatIntoNewString(
                            $left,
                            $right,
                            $leftOp,
                            $rightOp
                        );
                        $this->assignOperand($destOp, $newVal, true);
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        break;
                    }
                    // In-place `$r .= …` after `$r =& $obj->prop`: php-cfg marks the CV dead
                    // even though the property slot is live. Ephemeral concat never
                    // propertyStore's (leftover of #35898 / ZEND_ASSIGN_OP).
                    if (
                        (int) $op->arg1 === (int) $op->arg2
                        && null !== $left->objectPropertySlot
                        && null !== $left->objectPropertyType
                    ) {
                        $this->context->setVariableOp($destOp, $left);
                        $this->compileObjectPropertyConcatOp($left, $left, $right);
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        break;
                    }
                    // Always use entry-alloca for dead-operand concat results.
                    // assignOperand creates KIND_VALUE variables whose free() is a
                    // no-op, leaking the allocated string and corrupting the heap on
                    // repeated calls with multi-variable string interpolation
                    // (#24024, #23842, e19_const segfault).
                    $this->assignEphemeralConcatOperand(
                        $block,
                        $destOp,
                        $left,
                        $right,
                        $func,
                        $leftOp,
                        $rightOp
                    );
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                }
                // php-cfg leaves Concat.result as a dead Temporary before FuncCall;
                // Compiler wires ARG_SEND to that slot, but prologue never allocated it.
                // Skipping here made ARG_SEND materialize null via getVariableFromOp
                // (c04_concat: f($s.'1',$s.'2') → " \n" under AOT, #23779).
                if (!$this->context->hasVariableOp($destOp)) {
                    if (!$this->context->aliasVariableOpFromSlot($block, $destOp)) {
                        $leftOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
                        $rightOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3);
                        $left = $this->context->getVariableFromOp($leftOp);
                        $right = $this->context->getVariableFromOp($rightOp);
                        if (\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($left, $this->context)) {
                            \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                            break;
                        }
                        // Always entry-alloca for unset Concat.result (including ephemeral
                        // chain continuations) — KIND_VALUE links corrupt under AOT (#24024).
                        $this->assignEphemeralConcatOperand(
                            $block,
                            $destOp,
                            $left,
                            $right,
                            $func,
                            $leftOp,
                            $rightOp
                        );
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        break;
                    }
                }
                $result = $this->context->getVariableFromOp($destOp);
                $leftProbeOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
                if (
                    \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
                    || (
                        $this->context->hasVariableOp($leftProbeOp)
                        && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue(
                            $this->context->getVariableFromOp($leftProbeOp),
                            $this->context
                        )
                    )
                ) {
                    // Zend: Cannot use assign-op operators with string offsets (#22897).
                    \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                    break;
                }
                // Class static property lvalues: do not promote away staticPropertyGlobal
                // (#32899 / #32035 coalesce peer).
                $leftLive = $this->context->hasVariableOp($leftProbeOp)
                    ? $this->context->getVariableFromOp($leftProbeOp)
                    : null;
                $inPlaceStaticProp = (int) $op->arg1 === (int) $op->arg2
                    && null !== $leftLive
                    && null !== $leftLive->staticPropertyGlobal;
                if (null !== $result->staticPropertyGlobal || $inPlaceStaticProp) {
                    if ($inPlaceStaticProp && null === $result->staticPropertyGlobal) {
                        $result = $leftLive;
                        $this->context->setVariableOp($destOp, $result);
                    }
                    $left = $this->context->getVariableFromOp(
                        $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2)
                    );
                    $right = $this->context->getVariableFromOp(
                        $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3)
                    );
                    \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $left);
                    $newVal = $this->compileConcatIntoNewString(
                        $left,
                        $right,
                        $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2),
                        $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3)
                    );
                    $this->assignOperand($destOp, $newVal, true);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                }
                if (Variable::TYPE_STRING === $result->type && Variable::KIND_VALUE === $result->kind) {
                    $destOp = $block->getOperand($op->arg1);
                    $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                        $this->context,
                        $func,
                        $this->context->getTypeFromString('__string__*')
                    );
                    $promoted = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VARIABLE,
                        $slot
                    );
                    // Seed at function entry — not at the CONCAT site (may be a loop body) (#22845).
                    if (null !== $result->value) {
                        \PHPCompiler\JIT\BasicBlockHelper::storeAtFunctionEntry(
                            $this->context,
                            $func,
                            $result->value,
                            $slot
                        );
                        $promoted->addref();
                    } else {
                        \PHPCompiler\JIT\BasicBlockHelper::storeAtFunctionEntry(
                            $this->context,
                            $func,
                            $this->context->type->string->pointer->constNull(),
                            $slot
                        );
                    }
                    $this->context->setVariableOp($destOp, $promoted);
                    // In-place CONCAT may use an unnamed Temporary for the slot while
                    // the named `$out` Operand still points at the pre-promote alloca —
                    // bind by scoped name so left load and store share one slot (#22845).
                    $this->bindPromotedStringConcatDest($block, $destOp, $promoted);
                    if (null !== ($result->compileTimeString ?? null)) {
                        $promoted->compileTimeString = $result->compileTimeString;
                    }
                    $result = $promoted;
                }
                $left = $this->context->getVariableFromOp(
                    $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2)
                );
                $right = $this->context->getVariableFromOp(
                    $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3)
                );
                // FETCH_DIM_W orphan — ZEND_ASSIGN_DIM_OP for .= (#32798 / leftover #32789).
                \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $left);
                if (null !== $result->objectPropertySlot) {
                    $this->compileObjectPropertyConcatOp($result, $left, $right);
                } elseif (
                    null !== $result->writableHt
                    && (
                        Variable::TYPE_VALUE === $result->type
                        || \PHPCompiler\JIT\JitValueBox::isValueOperand($result)
                    )
                ) {
                    $newVal = $this->compileConcatIntoNewString(
                        $left,
                        $right,
                        $block->getOperand($op->arg2),
                        $block->getOperand($op->arg3)
                    );
                    // assignOperand commits into the HT (setAtIndex / setAtStringKey).
                    $this->assignOperand($destOp, $newVal, true);
                    if (null !== ($newVal->compileTimeString ?? null)) {
                        $result = $this->context->getVariableFromOp($destOp);
                        $result->compileTimeString = $newVal->compileTimeString;
                    }
                } elseif (Variable::TYPE_VALUE === $result->type || \PHPCompiler\JIT\JitValueBox::isValueOperand($result)) {
                    // {main} / untyped CVs stay TYPE_VALUE — `$buf .= …` used to alloc+memcpy
                    // both halves every iteration (str-builder / template-render). Promote the
                    // in-place CV to a native __string__** once, seed from the box, then
                    // appendInPlace like typed locals (#36386 / unfinished #36410).
                    $inPlaceBoxed = (int) $op->arg1 === (int) $op->arg2
                        && (
                            Variable::KIND_VARIABLE === $result->kind
                            || Variable::KIND_VALUE === $result->kind
                        )
                        && null === $result->objectPropertySlot
                        && null === $result->writableHt
                        && null === $result->staticPropertyGlobal
                        // `$s .= $s` must not realloc while the RHS aliases the same buffer.
                        && (int) $op->arg2 !== (int) $op->arg3;
                    if ($inPlaceBoxed) {
                        $destSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                            $this->context,
                            $func,
                            $this->context->getTypeFromString('__string__*')
                        );
                        $promoted = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VARIABLE,
                            $destSlot
                        );
                        \PHPCompiler\JIT\BasicBlockHelper::storeAtFunctionEntry(
                            $this->context,
                            $func,
                            $this->context->type->string->pointer->constNull(),
                            $destSlot
                        );
                        $this->seedNativeStringSlotFromValueBox($result, $destSlot);
                        $this->context->setVariableOp($destOp, $promoted);
                        $this->bindPromotedStringConcatDest($block, $destOp, $promoted);
                        if (null !== ($result->compileTimeString ?? null)) {
                            $promoted->compileTimeString = $result->compileTimeString;
                        }
                        // Drop value-box / script-global aliases before realloc so destSlot
                        // is the unique owner (rc=1). publish() re-addrefs after the grow.
                        // Shared destSlot+box at rc=2 was not enough when __ref__separate
                        // raced freelist reuse under {main} concat-temp traffic (#36386).
                        $this->dropValueBoxStringAliasIfSame($result, $destSlot);
                        $this->dropMainScriptStringAliasIfSame($block, $destOp, $destSlot);
                        // Grow in place for literal and dynamic RHS (#36386). Requires
                        // __string__realloc to store the moved pointer back into the
                        // `__string__**` slot (fixed this PR) — previously corrupted after
                        // the first geometric grow past the 32-byte initial cap.
                        $rightCoerced = \PHPCompiler\JIT\JitNativeString::coerce(
                            $this->context,
                            $right,
                            $block->getOperand($op->arg3)
                        );
                        $this->context->type->string->appendInPlace($promoted, $rightCoerced);
                        $newStr = $this->context->builder->load($destSlot);
                        $newVal = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VALUE,
                            $newStr
                        );
                        if (
                            null !== ($left->compileTimeString ?? null)
                            && null !== ($right->compileTimeString ?? null)
                        ) {
                            $newVal->compileTimeString = $left->compileTimeString.$right->compileTimeString;
                            $promoted->compileTimeString = $newVal->compileTimeString;
                        } else {
                            $promoted->compileTimeString = null;
                        }
                        // Real `static $s` inside a function still needs the module box
                        // updated. {main} script-globals also set functionStaticGlobal
                        // (ensureScriptGlobal) — do not republish those here: the trailing
                        // publishConcatResultToMainScriptGlobal / per-iter writeString
                        // addrefs the same buffer so appendInPlace always COWs (#36386).
                        if ($result->functionStaticGlobal && !$block->isMainScript()) {
                            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $result),
                                $newVal
                            );
                            \PHPCompiler\JIT\JitValueBox::publishAfterWrite(
                                $this->context,
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $result)
                            );
                        }
                        // Skip per-iter {main} script-global publish: destSlot is the
                        // unique owner after drop*; republishing addrefs so the next
                        // __ref__separate COWs O(n) (#36386). Echo/strlen use the
                        // promoted native slot (bindPromotedStringConcatDest). php-src:
                        // Zend/zend_operators.c ZEND_ASSIGN_CONCAT / zend_string_extend.
                        $this->markScopeVariableAssignedIfTracked($destOp, $promoted);
                        $result = $promoted;
                    } else {
                        $newVal = $this->compileConcatIntoNewString(
                            $left,
                            $right,
                            $block->getOperand($op->arg2),
                            $block->getOperand($op->arg3)
                        );
                        \PHPCompiler\JIT\JitValueBox::assignToPointer(
                            $this->context,
                            $this->valueBoxPointer($result),
                            $newVal
                        );
                        \PHPCompiler\JIT\JitValueBox::publishAfterWrite(
                            $this->context,
                            $this->valueBoxPointer($result)
                        );
                        if (null !== ($newVal->compileTimeString ?? null)) {
                            $result->compileTimeString = $newVal->compileTimeString;
                        }
                        $this->publishMainScriptNamedConcatResult($block, $destOp, $newVal);
                        $this->markScopeVariableAssignedIfTracked($destOp, $result);
                    }
                } else {
                    // Resolve/promote the native string slot before concat (#22845).
                    $destSlot = $result->value;
                    $destSlotTy = null !== $destSlot
                        ? $this->context->getStringFromType($destSlot->typeOf())
                        : '';
                    $hasStringAlloca = Variable::KIND_VARIABLE === $result->kind
                        && ('__string__**' === $destSlotTy || 'ptr' === $destSlotTy);
                    if (!$hasStringAlloca && (
                        null === $destSlot
                        || Variable::KIND_VALUE === $result->kind
                        || ('__string__*' !== $destSlotTy && '__string__**' !== $destSlotTy)
                    )) {
                        $destSlot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                            $this->context,
                            $func,
                            $this->context->getTypeFromString('__string__*')
                        );
                        $promoted = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VARIABLE,
                            $destSlot
                        );
                        // Seed at entry so loop-carried CONCAT does not reset (#22845).
                        $seed = null !== $result->value
                            && Variable::KIND_VALUE === $result->kind
                            ? $result->value
                            : $this->context->type->string->pointer->constNull();
                        \PHPCompiler\JIT\BasicBlockHelper::storeAtFunctionEntry(
                            $this->context,
                            $func,
                            $seed,
                            $destSlot
                        );
                        $this->context->setVariableOp($destOp, $promoted);
                        $this->bindPromotedStringConcatDest($block, $destOp, $promoted);
                        $result = $promoted;
                        $hasStringAlloca = true;
                    }
                    // `$s .= $rhs` on a native slot: grow in place (#36410 / #36386).
                    // Dynamic and literal share appendInPlace now that realloc publishes
                    // the moved pointer (#36386).
                    if ((int) $op->arg1 === (int) $op->arg2 && $hasStringAlloca) {
                        // {main} publishMainScriptNamedConcatResult addrefs the same
                        // buffer into a script-global box. Without dropping that alias,
                        // __ref__separate COWs the whole string on every .= (#36386).
                        $this->dropMainScriptStringAliasIfSame($block, $destOp, $destSlot);
                        $rightCoerced = \PHPCompiler\JIT\JitNativeString::coerce(
                            $this->context,
                            $right,
                            $block->getOperand($op->arg3)
                        );
                        $this->context->type->string->appendInPlace($result, $rightCoerced);
                        $newStr = $this->context->builder->load($destSlot);
                    } else {
                        $leftVar = $this->context->helper->loadValue(
                            \PHPCompiler\JIT\JitNativeString::coerce($this->context, $left, $block->getOperand($op->arg2))
                        );
                        $rightVar = $this->context->helper->loadValue(
                            \PHPCompiler\JIT\JitNativeString::coerce($this->context, $right, $block->getOperand($op->arg3))
                        );
                        $newStr = \PHPCompiler\ext\standard\JitStringConcat::concat(
                            $this->context,
                            $leftVar,
                            $rightVar
                        );
                        $this->context->builder->store($newStr, $destSlot);
                    }
                    $nativeConcatVal = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        $newStr
                    );
                    // In-place {main} $buf .= already mutated destSlot. Re-publishing
                    // into the script-global box every iteration addrefs the same
                    // buffer so the next __ref__separate COWs O(n) (#36386). Echo /
                    // strlen read the promoted native slot via bindPromotedStringConcatDest.
                    // Publish only when the CONCAT was not in-place (new buffer).
                    if ((int) $op->arg1 !== (int) $op->arg2 || !$hasStringAlloca) {
                        $this->publishMainScriptNamedConcatResult($block, $destOp, $nativeConcatVal);
                    }
                }
                if (
                    null !== ($left->compileTimeString ?? null)
                    && null !== ($right->compileTimeString ?? null)
                ) {
                    $result->compileTimeString = $left->compileTimeString.$right->compileTimeString;
                } else {
                    $leftResolved = $left->compileTimeString
                        ?? $this->resolveJitCompileTimeStringSlot($block, (int) $op->arg2);
                    $rightResolved = $right->compileTimeString
                        ?? $this->resolveJitCompileTimeStringSlot($block, (int) $op->arg3);
                    if (null !== $leftResolved && null !== $rightResolved) {
                        $result->compileTimeString = $leftResolved.$rightResolved;
                    }
                }
                $scopeName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                if (
                    null !== $scopeName
                    && '' !== $scopeName
                    && null !== ($result->compileTimeString ?? null)
                ) {
                    $resolvedName = $this->context->resolveRefAliasName($scopeName);
                    if (isset($this->context->namedVariableBindings[$resolvedName])) {
                        $this->context->namedVariableBindings[$resolvedName]->compileTimeString
                            = $result->compileTimeString;
                    }
                }
                // {main} `$out = $a.$b` must populate the script-global heap box that
                // attachEchoScriptGlobalName makes ECHO read. Native-string CONCAT only
                // wrote a local `__string__*` alloca (#36366). Use the opcode $block
                // (not jitEnclosingBlock) — same predicate echo uses for script-globals.
                //
                // In-place `$buf .= …` that already owns a promoted `__string__**` must
                // NOT republish: assignToPointer addrefs so the next appendInPlace sees
                // rc>1 and __ref__separate COWs O(n) every iteration (#36386). Echo /
                // strlen already prefer the promoted native binding.
                $inPlaceNativeAppend = (int) $op->arg1 === (int) $op->arg2
                    && Variable::TYPE_STRING === $result->type
                    && Variable::KIND_VARIABLE === $result->kind;
                if ($inPlaceNativeAppend) {
                    $this->markScopeVariableAssignedIfTracked($destOp, $result);
                    $bindName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                    if (null === $bindName || '' === $bindName) {
                        $slot = $block->slotForOperand($destOp);
                        if (null !== $slot) {
                            foreach ($block->scopedOperands() as $scopeOp) {
                                if ($block->slotForOperand($scopeOp) !== $slot) {
                                    continue;
                                }
                                $bindName = \PHPCompiler\JIT\OperandName::resolve($scopeOp);
                                if (null !== $bindName && '' !== $bindName) {
                                    break;
                                }
                            }
                        }
                    }
                    if (null !== $bindName && '' !== $bindName) {
                        $this->context->bindVariableByName(
                            $this->context->resolveRefAliasName($bindName),
                            $result
                        );
                    }
                } elseif (!$this->publishConcatResultToMainScriptGlobal(
                    $block,
                    $destOp,
                    $result,
                    (int) $op->arg1
                )) {
                    $this->markScopeVariableAssignedIfTracked($destOp, $result);
                    // Keep named binding on the native string alloca so ECHO can prefer
                    // it over an empty script-global box (#36366).
                    $bindName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                    if (null === $bindName || '' === $bindName) {
                        $slot = $block->slotForOperand($destOp);
                        if (null !== $slot) {
                            foreach ($block->scopedOperands() as $scopeOp) {
                                if ($block->slotForOperand($scopeOp) !== $slot) {
                                    continue;
                                }
                                $bindName = \PHPCompiler\JIT\OperandName::resolve($scopeOp);
                                if (null !== $bindName && '' !== $bindName) {
                                    break;
                                }
                            }
                        }
                    }
                    if (null !== $bindName && '' !== $bindName) {
                        $this->context->bindVariableByName(
                            $this->context->resolveRefAliasName($bindName),
                            $result
                        );
                    }
                }
                $this->maybeRefreshIncludeBindingsBeforeUse();
                break;
        }
    }
}
