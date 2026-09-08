<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Web\Superglobals;
use PHPLLVM;

/**
 * Dead-temp free for {@see Context} (#36387).
 *
 * Extracted from {@see ContextVariableOperandBinding}; CONST_FETCH moved to
 * {@see ContextConstantFetch} so freeDeadVariables stays a separate TU from
 * constant materialization (split-TU / size-budget ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextFreeDeadAndConstantFetch;} on {@see Context}.
 * CONST_FETCH: {@see ContextConstantFetch}.
 *
 * No new C ABI. php-src analogy: temporary dtor after ZEND_ASSIGN / at return
 * (Zend/zend_execute.c) lives beside the executor rather than inside CV slot
 * binding or zend_get_constant_str.
 */
trait ContextFreeDeadAndConstantFetch
{
    public function freeDeadVariables(
        PHPLLVM\Value\Function_ $func,
        PHPLLVM\BasicBlock $basicBlock,
        Block $block,
        ?Operand $skipOperand = null
    ): void {
        // Callers pass the BB that owns the frees. NestedJIT may have cleared insert —
        // re-park on that BB when it is still open. Do not jump to an unrelated lastOpen
        // (dominate failures on mid-fn value boxes, #36382).
        $insert = BasicBlockHelper::tryGetInsertBlock($this);
        if (null === $insert) {
            if (null !== $basicBlock->getTerminator()) {
                return;
            }
            $this->builder->positionAtEnd($basicBlock);
        } elseif (null !== $insert->getTerminator()) {
            if (null === $basicBlock->getTerminator()) {
                $this->builder->positionAtEnd($basicBlock);
            } else {
                BasicBlockHelper::ensureOpenInsertBlock($this, 'free_dead_vars_cont');
            }
        }
        $coalesceResults = new \SplObjectStorage();
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_COALESCE === $blockOp->type && null !== $blockOp->block3) {
                $coalesceResults[$block->getOperand($blockOp->arg1)] = true;
            }
        }
        $returnOperands = new \SplObjectStorage();
        $returnSlots = [];
        foreach ($block->opCodes as $blockOp) {
            // TYPE_THROW must keep its operand alive the same way RETURN does: uncaught
            // emitThrow calls freeDeadVariables before instanceof Throwable, and freeing the
            // Exception object made `return throw new …` / `fn()=>throw new …` look like a
            // non-Throwable (or SIGSEGV) under AOT (#34868, peer #34859).
            if (
                (OpCode::TYPE_RETURN !== $blockOp->type && OpCode::TYPE_THROW !== $blockOp->type)
                || null === $blockOp->arg1
            ) {
                continue;
            }
            $returnOp = $block->getOperand($blockOp->arg1);
            $returnOperands[$returnOp] = true;
            $returnSlots[(int) $blockOp->arg1] = true;
        }
        if (null !== $skipOperand) {
            $returnOperands[$skipOperand] = true;
            $skipSlot = $block->slotForOperand($skipOperand);
            if (null !== $skipSlot) {
                $returnSlots[$skipSlot] = true;
            }
        }
        foreach ($this->coalesceAssignTargets as $mergeOp) {
            $returnOperands[$mergeOp] = true;
            $mergeSlot = $block->slotForOperand($mergeOp);
            if (null !== $mergeSlot) {
                $returnSlots[$mergeSlot] = true;
            }
        }
        // Match/?: echo merge stack slots must survive trailing JUMPIF in the same
        // block (second `echo match` after the first merge) (#24143).
        foreach ($this->coalesceMergeSlotOperands as $mergeSlot => $mergeSlotOp) {
            $returnOperands[$mergeSlotOp] = true;
            $returnSlots[(int) $mergeSlot] = true;
            $resolved = $block->slotForOperand($mergeSlotOp);
            if (null !== $resolved) {
                $returnSlots[$resolved] = true;
            }
        }
        // Pending call args already SENDed must survive later ?? / sub-block freeDead
        // before FUNCCALL_EXEC (e.g. `new App(self::make(), self::$o ?? null)` — Slim
        // AppFactory::create). php-src zend_send_by_val keeps the zval on the VM stack
        // until DO_FCALL; freeing the producer temp here UAF'd the object (#36382).
        foreach ($this->scope->argOperands as $pendingArgOp) {
            if (!$pendingArgOp instanceof Operand) {
                continue;
            }
            $returnOperands[$pendingArgOp] = true;
            $pendingSlot = $block->slotForOperand($pendingArgOp);
            if (null !== $pendingSlot) {
                $returnSlots[$pendingSlot] = true;
            }
        }
        $returnVarNames = [];
        foreach ($returnOperands as $returnOp) {
            $name = OperandName::resolve($returnOp);
            if (null !== $name) {
                $returnVarNames[$name] = true;
            }
        }
        $isUserFunctionReturnVoid = false;
        if (null !== $block->func) {
            $fnName = $block->func->name;
            if ('{main}' !== $fnName && !str_ends_with($fnName, '::__destruct')) {
                foreach ($block->opCodes as $blockOp) {
                    if (
                        OpCode::TYPE_RETURN_VOID === $blockOp->type
                        || (OpCode::TYPE_RETURN === $blockOp->type && null === $blockOp->arg1)
                    ) {
                        $isUserFunctionReturnVoid = true;
                        break;
                    }
                }
            }
        }
        foreach ($block->orig->deadOperands as $op) {
            if ($isUserFunctionReturnVoid) {
                // releaseJitFunctionLocalsAtReturn owns named CV delref. Shadow
                // allocas of those CVs must not delref again. Distinct NEW-result
                // temps keep their own addref and must fall through to free()
                // (Zend/zend_execute.c temp dtor after ZEND_ASSIGN; #36245).
                $name = OperandName::resolve($op);
                if (null !== $name && '' !== $name) {
                    continue;
                }
                if (!$this->scope->variables->contains($op)) {
                    continue;
                }
                $var = $this->scope->variables[$op];
                if (
                    Variable::TYPE_OBJECT === $var->type
                    && Variable::KIND_VARIABLE === $var->kind
                    && null !== $var->value
                    && $this->objectMirrorSharesNamedCvAlloca($var)
                ) {
                    $slotTy = $var->value->typeOf();
                    if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                        $this->builder->store(
                            $slotTy->getElementType()->constNull(),
                            $var->value
                        );
                    }
                    continue;
                }
            }
            if ($returnOperands->contains($op)) {
                continue;
            }
            $deadSlot = $block->slotForOperand($op);
            if (null !== $deadSlot && isset($returnSlots[$deadSlot])) {
                continue;
            }
            $name = OperandName::resolve($op);
            if (null !== $name && isset($returnVarNames[$name])) {
                continue;
            }
            // releaseJitFunctionLocalsAtReturn already delref'd named CVs; freeing
            // them again here drops orphan cycles to refcount 0 (#36245 scope_exit).
            if ($isUserFunctionReturnVoid && null !== $name && '' !== $name) {
                continue;
            }
            if ($coalesceResults->contains($op)) {
                continue;
            }
            if (!$this->scope->variables->contains($op)) {
                continue;
            }
            $var = $this->scope->variables[$op];
            $name = OperandName::resolve($op);
            if (
                null !== $var->superglobalName
                || (null !== $name && Superglobals::isSuperglobalName($name))
                || 'this' === $name
            ) {
                continue;
            }
            $var->free();
        }
    }
}
