<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * ASSIGN opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_ASSIGN}. Wrapped in
 * {@code switch (true)} so original case-level {@code break} semantics are
 * preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_ASSIGN / ZEND_ASSIGN_DIM / ZEND_ASSIGN_OBJ
 * paths that lower through CFG Assign), Zend/zend_execute.c — move-only Concern
 * extract; no new C ABI.
 */
trait CompileAssign
{
    /**
     * @param Variable ...$args LLVM / NestedJIT callee args (by-ref formal fast path)
     */
    private function compileAssignOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock,
        int $thisParamOffset,
        Variable ...$args
    ): void {
        switch (true) {
            case true:
                    \PHPCompiler\JIT\BasicBlockHelper::repositionToLastOpenIfInsertLost($this->context);
                    // Stamp assign opline so mid-method private(set) Errors match Zend (#29665).
                    if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                        $this->context->callSiteLine = $op->sourceLocation->startLine;
                    }
                    $rhsSlot = $this->assignRhsSlot($op);
                    if (
                        $i > 0
                        && null !== $op->arg2
                        && $op->arg1 !== $op->arg2
                    ) {
                        $prevAssignOp = $block->opCodes[$i - 1];
                        if (
                            (
                                OpCode::TYPE_INIT_ARRAY === $prevAssignOp->type
                                || OpCode::TYPE_ADD_ARRAY_ELEMENT === $prevAssignOp->type
                            )
                            && null !== $prevAssignOp->arg1
                            && (int) $prevAssignOp->arg1 === $rhsSlot
                        ) {
                            $phiSlot = (int) $op->arg2;
                            $phiOp = $this->context->coalesceMergeSlotOperands[$phiSlot] ?? null;
                            if ($phiOp instanceof Operand) {
                                $initRhsOp = $block->getOperand($rhsSlot);
                                assert(null !== $initRhsOp);
                                $arrayValue = $this->context->getVariableFromOp($initRhsOp);
                                if (!$this->context->hasVariableOp($phiOp)) {
                                    $this->ensureCoalesceMergeStackSlot($phiOp);
                                }
                                $phiVar = $this->context->getVariableFromOp($phiOp);
                                if (
                                    Variable::TYPE_VALUE === $phiVar->type
                                    && Variable::KIND_VARIABLE === $phiVar->kind
                                ) {
                                    \PHPCompiler\JIT\JitValueBox::assignToPointer(
                                        $this->context,
                                        \PHPCompiler\JIT\JitValueBox::pointer($this->context, $phiVar->value),
                                        $arrayValue
                                    );
                                    \PHPCompiler\JIT\JitValueBox::publishAfterWrite(
                                        $this->context,
                                        \PHPCompiler\JIT\JitValueBox::pointer($this->context, $phiVar->value)
                                    );
                                } else {
                                    $this->assignOperand($phiOp, $arrayValue, true);
                                }
                                $this->bindCoalesceMergeSlotVariable(
                                    $block,
                                    $phiSlot,
                                    $this->context->getVariableFromOp($phiOp)
                                );
                                break;
                            }
                        }
                    }
                    $rhsOperand = $block->getOperand($rhsSlot);
                    if (isset($this->context->coalesceMergeSlotOperands[(int) $rhsSlot])) {
                        $value = $this->materializeCoalesceMergeSlotArgSend(
                            $block,
                            $this->context->coalesceMergeSlotOperands[(int) $rhsSlot]
                        );
                    } else {
                        $rhsName = $this->resolveLocalNameForOperand($block, $rhsOperand, $rhsSlot);
                        $value = $this->resolveScriptGlobalForRuntimeRead($rhsOperand, $block, $rhsName);
                        if (null === $value) {
                            $formalRhs = $this->tryResolveFormalParamVariableForRhs($block, $rhsOperand);
                            if (null !== $formalRhs) {
                                $value = $formalRhs;
                            } else {
                                $value = $this->context->getVariableFromOp($rhsOperand);
                                if (null !== $rhsName && '' !== $rhsName) {
                                    $resolvedRhs = $this->context->resolveRefAliasName($rhsName);
                                    if (isset($this->context->namedVariableBindings[$resolvedRhs])) {
                                        $value = $this->context->namedVariableBindings[$resolvedRhs];
                                    }
                                }
                                // `@$undef` / `$a = $undef` TYPE_ASSIGN RHS: ZEND_CHECK_UNDEFINED_VAR
                                // even when the CV is already in namedVariableBindings (#32041).
                                if ($rhsOperand instanceof Operand) {
                                    \PHPCompiler\JIT\UndefinedVariableHelper::guardBeforeNamedLocalRead(
                                        $this->context,
                                        $rhsOperand,
                                        $value
                                    );
                                }
                            }
                        }
                    }
                    $destOp = $block->getOperand($op->arg1);
                    $aliasOp = $block->getOperand($op->arg2);
                    // XMLReader::XML() ASSIGN into CFG-bool `$reader` — promote slots to VALUE
                    // so property fetches keep classUserType (#28670).
                    if ('XMLReader' === ($value->classUserType ?? '')) {
                        foreach ([$destOp, $aliasOp] as $tagOp) {
                            if (!$tagOp instanceof Operand) {
                                continue;
                            }
                            $tagOp->type = new Type(Type::TYPE_OBJECT, [], 'XMLReader');
                            if ($this->context->hasVariableOp($tagOp)) {
                                $ex = $this->context->getVariableFromOp($tagOp);
                                if (
                                    Variable::TYPE_VALUE !== $ex->type
                                    && Variable::TYPE_OBJECT !== $ex->type
                                ) {
                                    $ex->free();
                                    unset($this->context->scope->variables[$tagOp]);
                                }
                            }
                        }
                    }
                    // DOMElement::removeAttributeNode() — same CFG-bool → object ASSIGN (#32707).
                    if ('DOMAttr' === ($value->classUserType ?? '')) {
                        foreach ([$destOp, $aliasOp] as $tagOp) {
                            if (!$tagOp instanceof Operand) {
                                continue;
                            }
                            $tagOp->type = new Type(Type::TYPE_OBJECT, [], 'DOMAttr');
                            if ($this->context->hasVariableOp($tagOp)) {
                                $ex = $this->context->getVariableFromOp($tagOp);
                                if (
                                    Variable::TYPE_VALUE !== $ex->type
                                    && Variable::TYPE_OBJECT !== $ex->type
                                ) {
                                    $ex->free();
                                    unset($this->context->scope->variables[$tagOp]);
                                }
                            }
                        }
                    }
                    $value = $this->resolveAssignRhsFromFormalParam($block, $rhsOperand, $value);
                    // `$sink = $obj->nodeName` must copy the string, not alias the live slot (#34465).
                    $value = $this->detachScalarObjectPropertyAliasForAssign($value);
                    if (null !== $this->context->ternarySharedReturnSlot && $this->isTernaryBranchMergeAssign($block, $op)) {
                        $this->emitJitReturnFromValue($func, $block, $value);
                        break;
                    }
                    $coalesceTarget = $this->resolveCoalesceMergeAssignTarget($destOp, $aliasOp, $block);
                    if (
                        null === $coalesceTarget
                        && $i > 0
                        && null !== $aliasOp
                        && $op->arg1 !== $op->arg2
                        // Only when arg2 is a real ?: / ?? merge phi — bare `$a = [1]` matches
                        // INIT_ARRAY(temp); ASSIGN(result, $a, temp) and must not forceCoalesce
                        // (#35258 / re-#33709 leftover of #34956/#34970).
                        && isset($this->context->coalesceMergeSlotOperands[(int) $op->arg2])
                    ) {
                        $prevOp = $block->opCodes[$i - 1];
                        if (
                            (
                                OpCode::TYPE_INIT_ARRAY === $prevOp->type
                                || OpCode::TYPE_ADD_ARRAY_ELEMENT === $prevOp->type
                            )
                            && null !== $prevOp->arg1
                            && (int) $prevOp->arg1 === $rhsSlot
                        ) {
                            // INIT_ARRAY(temp); [ADD_ARRAY_ELEMENT]*; ASSIGN(result, phi, temp)
                            // else/true-arm of ?: (#34956/#34970).
                            $coalesceTarget = $aliasOp;
                        }
                    }
                    $forceCoalesce = null !== $coalesceTarget;
                    $srcOp = $block->getOperand($rhsSlot);
                    $isNullSource = $value->isNullConstant
                        || Variable::TYPE_NULL === $value->type
                        || ($srcOp instanceof Operand\Literal && null === $srcOp->value);
                    if ($forceCoalesce && $isNullSource) {
                        if (!$this->context->hasVariableOp($coalesceTarget)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $coalesceTarget);
                        }
                        $this->persistPropertyBeforeCoalesceMergePromote($coalesceTarget, $value);
                        $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        if (Variable::KIND_VALUE === $mergeDest->kind) {
                            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                            $this->context->setVariableOp(
                                $coalesceTarget,
                                new Variable(
                                    $this->context,
                                    Variable::TYPE_VALUE,
                                    Variable::KIND_VARIABLE,
                                    $slot
                                )
                            );
                            $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        }
                        if (
                            Variable::TYPE_VALUE === $mergeDest->type
                            && Variable::KIND_VARIABLE === $mergeDest->kind
                        ) {
                            $this->context->builder->call(
                                $this->context->lookupFunction('__value__writeNull'),
                                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $mergeDest->value)
                            );
                            // Do not set isNullConstant on shared ?-> / ?? merge slots — the
                            // fetch arm is compiled later against the same Variable and would
                            // see a stale null constant after writing a real value (#34024).

                            break;
                        }
                    }
                    if ($forceCoalesce && !$isNullSource) {
                        if (!$this->context->hasVariableOp($coalesceTarget)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $coalesceTarget);
                        }
                        $this->persistPropertyBeforeCoalesceMergePromote($coalesceTarget, $value);
                        $mergeDest = $this->context->getVariableFromOp($coalesceTarget);
                        if (
                            Variable::TYPE_VALUE !== $mergeDest->type
                            || Variable::KIND_VARIABLE !== $mergeDest->kind
                        ) {
                            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                            $this->context->setVariableOp(
                                $coalesceTarget,
                                new Variable(
                                    $this->context,
                                    Variable::TYPE_VALUE,
                                    Variable::KIND_VARIABLE,
                                    $slot
                                )
                            );
                        }
                    }
                    $forceAssign = $forceCoalesce
                        || $this->assignOperandsUsedByLiteralInclude($block, $op);
                    $ternaryEchoPhiDest = null;
                    if (
                        $forceCoalesce
                        && null !== $coalesceTarget
                        && null !== $aliasOp
                        && $op->arg1 !== $op->arg2
                    ) {
                        // php-cfg ?: arms use ASSIGN(resultTemp, phiAlias, rhs). Null arms hit
                        // forceCoalesce+isNullSource above; non-null else arms (e.g. `['x']`)
                        // must write the stack phi at phiAlias, not only the dead result temp (#34956).
                        // JUMPIF arms reuse slot numbers with distinct Operand instances — resolve
                        // via coalesceMergeSlotOperands so `$x = ($o ? $o->p : 'null')` false arm
                        // does not assign into a stale fetch-arm objectPropertySlot (#23514).
                        $ternaryEchoPhiDest = $coalesceTarget;
                    }
                    $aliasName = null !== $aliasOp ? \PHPCompiler\JIT\OperandName::resolve($aliasOp) : null;
                    $needsNamedStorageAssign = null !== $aliasOp
                        && $op->arg1 !== $op->arg2
                        && null !== $aliasName
                        && '' !== $aliasName
                        && null === \PHPCompiler\JIT\OperandName::resolve($destOp);
                    if ($needsNamedStorageAssign && !$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    if (
                        null !== $aliasOp
                        && $this->context->hasVariableOp($aliasOp)
                        && $this->context->hasVariableOp($block->getOperand($rhsSlot))
                    ) {
                        $aliasVar = $this->context->getVariableFromOp($aliasOp);
                        $srcVar = $this->context->getVariableFromOp($block->getOperand($rhsSlot));
                        if ($aliasVar === $srcVar) {
                            $prior = $i > 0 ? $block->opCodes[$i - 1] : null;
                            if (
                                null !== $prior
                                && null !== $destOp
                                && $this->context->coalesceAssignTargets->contains($destOp)
                                && null !== $this->ternaryEchoPhiPropertyFetchDest($block, $i - 1)
                            ) {
                                $this->recordTernaryEchoPhiByAliasSlot($block, $op, $destOp, $aliasOp, $rhsSlot);
                                break;
                            }
                            if ([] !== $destOp->usages || $forceAssign) {
                                $this->assignOperand($destOp, $value, $forceAssign);
                            }
                            $this->propagateAssignAliasBinding($destOp, $aliasOp, false);
                            $this->maybeBindNamedVariable($aliasOp);
                            $this->recordTernaryEchoPhiByAliasSlot($block, $op, $destOp, $aliasOp, $rhsSlot);
                            break;
                        }
                    }
                    if (null !== $ternaryEchoPhiDest) {
                        $this->assignOperand($ternaryEchoPhiDest, $value, true);
                        if ($this->context->hasVariableOp($ternaryEchoPhiDest)) {
                            $phiVar = $this->context->getVariableFromOp($ternaryEchoPhiDest);
                            if (
                                Variable::TYPE_VALUE === $phiVar->type
                                && Variable::KIND_VARIABLE === $phiVar->kind
                            ) {
                                $phiPtr = \PHPCompiler\JIT\JitValueBox::pointer($this->context, $phiVar->value);
                                \PHPCompiler\JIT\JitValueBox::assignToPointer(
                                    $this->context,
                                    $phiPtr,
                                    $value
                                );
                                \PHPCompiler\JIT\JitValueBox::publishAfterWrite($this->context, $phiPtr);
                                $this->foldCompileTimeStringFromAssign(
                                    $block,
                                    $rhsSlot,
                                    $phiVar,
                                    $value
                                );
                            }
                        }
                        if (null !== $op->arg2) {
                            $this->bindCoalesceMergeSlotVariable(
                                $block,
                                (int) $op->arg2,
                                $this->context->getVariableFromOp($ternaryEchoPhiDest)
                            );
                        }
                        $this->recordTernaryEchoPhiByAliasSlot($block, $op, $destOp, $aliasOp, $rhsSlot);
                        break;
                    }
                    if ($needsNamedStorageAssign) {
                        if (
                            null === $this->byRefFormalParamIndexForAssignDest($block, $aliasOp)
                            && !$this->context->hasVariableOp($aliasOp)
                        ) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $aliasOp);
                        }
                        $this->emitAssignOperandWithByRefFormalFastPath(
                            $block,
                            $aliasOp,
                            $rhsOperand,
                            $value,
                            $args,
                            $thisParamOffset,
                            true
                        );
                        $aliasVar = $this->context->getVariableFromOp($aliasOp);
                        // `$f = function () use ($n) { ... }` — named storage assign must keep
                        // ClosureWithCaptures on `$f` or AOT invoke drops use() snapshots (#24106).
                        $this->preserveClosureInvokeMetadata($aliasOp, $aliasVar, $value);
                        $this->recordListUnpackAssignSlot($aliasOp, $aliasVar);
                        if (null !== $destOp) {
                            $destUsed = [] !== $destOp->usages;
                            // `$o->p ??= $x = n` reads Assign.result, not `$x` (#35998 / #29747).
                            $resultConsumedLater = null !== $op->arg1
                                && $op->arg1 !== $op->arg2
                                && $op->arg1 !== $rhsSlot
                                && !$block->assignTempSlotIsDead((int) $op->arg1);
                            if ($destUsed || $forceAssign || $resultConsumedLater) {
                                $this->emitAssignOperandWithByRefFormalFastPath(
                                    $block,
                                    $destOp,
                                    $rhsOperand,
                                    $value,
                                    $args,
                                    $thisParamOffset,
                                    $destUsed || $forceAssign || $resultConsumedLater
                                );
                            }
                        }
                    } else {
                        if (null !== $aliasOp) {
                            $this->emitAssignOperandWithByRefFormalFastPath(
                                $block,
                                $aliasOp,
                                $rhsOperand,
                                $value,
                                $args,
                                $thisParamOffset,
                                $forceAssign
                            );
                        }
                        if (null !== $destOp) {
                            $destUsed = [] !== $destOp->usages;
                            // php-cfg Assign result temps often have empty Operand::$usages when the
                            // value only feeds ARG_SEND (match subject snapshot → message helper).
                            // Still write the result so ARG_SEND does not read an uninitialized
                            // null value box (#29747 / Block::assignResultSlotConsumedByLaterOp).
                            $resultConsumedLater = null !== $op->arg1
                                && $op->arg1 !== $op->arg2
                                && $op->arg1 !== $rhsSlot
                                && !$block->assignTempSlotIsDead((int) $op->arg1);
                            if ($destUsed || $forceAssign || $resultConsumedLater) {
                                $this->emitAssignOperandWithByRefFormalFastPath(
                                    $block,
                                    $destOp,
                                    $rhsOperand,
                                    $value,
                                    $args,
                                    $thisParamOffset,
                                    $destUsed || $forceAssign || $resultConsumedLater
                                );
                            }
                        }
                    }
                    $srcOp = $block->getOperand($rhsSlot);
                    if ($op->arg2 !== $rhsSlot && $block->assignTempSlotIsDead($rhsSlot)) {
                        $this->jitClearAssignTempOperand($srcOp);
                    }
                    if (
                        !$needsNamedStorageAssign
                        && $op->arg1 !== $op->arg2
                        && $op->arg1 !== $rhsSlot
                        && $block->assignTempSlotIsDead((int) $op->arg1)
                    ) {
                        $this->jitClearAssignTempOperand($destOp);
                    }
                    if (null !== $aliasOp) {
                        if ($op->arg1 !== $op->arg2) {
                            $this->propagateAssignAliasBinding($destOp, $aliasOp, $needsNamedStorageAssign);
                        }
                        $this->maybeBindNamedVariable($aliasOp);
                        // Only ternary/?? merge assigns need echo→phi redirect (#18052). Recording
                        // every `$n = 1` made later by-ref updates invisible to echo (#24162).
                        if (
                            $forceCoalesce
                            || (
                                null !== $destOp
                                && $this->context->coalesceAssignTargets->contains($destOp)
                            )
                            || $this->context->coalesceAssignTargets->contains($aliasOp)
                        ) {
                            $this->recordTernaryEchoPhiByAliasSlot(
                                $block,
                                $op,
                                $destOp,
                                $aliasOp,
                                $rhsSlot
                            );
                        }
                    }
                    if ($op->arg1 === $op->arg2 && null !== $destOp) {
                        $this->maybeBindNamedVariable($destOp);
                    }
                    foreach ([$aliasOp, $destOp] as $destOperand) {
                        if (null === $destOperand || !$this->context->hasVariableOp($destOperand)) {
                            continue;
                        }
                        $destVar = $this->context->getVariableFromOp($destOperand);
                        $this->foldCompileTimeStringFromAssign(
                            $block,
                            $rhsSlot,
                            $destVar,
                            $value
                        );
                    }
                    break;  
        }
    }
}
