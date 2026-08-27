<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\JIT\Builtin\ExceptionHandlerJitRuntime;
use PHPCompiler\JIT\Builtin\JitReturnPending;
use PHPCompiler\JIT\Builtin\ExceptionThrowToStringSeed;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Builtin\ScriptExit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\UncaughtThrowPrinter;
use PHPCompiler\JIT\Builtin\GetClassRuntime;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\ext\standard\VmEval;
use PHPTypes\Type;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * LLVM lowering for try/catch/throw within a single JIT function (issues #57, #2084, #1056, #2157).
 */
final class TryCatchHelper
{
    /** @var list<TryCatchHandler> */
    public array $handlerStack = [];

    /** @var array<int, TryCatchHandler> merge block id => handler */
    public array $mergeHandlers = [];

    /**
     * @return list<array{op: OpCode, catchTypes: list<string>}>
     */
    public static function collectCatchOps(Block $handlerBlock, int $afterTryIndex): array
    {
        $arms = [];
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $next = $handlerBlock->opCodes[$j];
            if (OpCode::TYPE_JUMP === $next->type) {
                continue;
            }
            if (OpCode::TYPE_CATCH === $next->type) {
                $types = [];
                $encoded = $next->catchTypes;
                if (null !== $encoded && '' !== $encoded) {
                    foreach (explode('|', $encoded) as $typeName) {
                        $typeName = strtolower(ltrim($typeName, '\\'));
                        if ('' !== $typeName) {
                            $types[] = $typeName;
                        }
                    }
                }
                $arms[] = ['op' => $next, 'catchTypes' => $types];
                continue;
            }
            if (OpCode::TYPE_FINALLY === $next->type) {
                break;
            }
            break;
        }

        return $arms;
    }

    /** php-cfg try body ends with JUMP to else or merge; compileSubBlock omits that opcode (#19128). */
    private static function tryBodyTrailingJumpTarget(?Block $tryBody): ?Block
    {
        if (null === $tryBody || 0 === $tryBody->nOpCodes) {
            return null;
        }
        $last = $tryBody->opCodes[$tryBody->nOpCodes - 1];
        if (OpCode::TYPE_JUMP !== $last->type) {
            return null;
        }

        return $last->block1;
    }

    /**
     * Normal finally fallthrough when no return/throw/leave pending (#35547 nested).
     *
     * Inner finally CFG often ends with JUMP to an enclosing finally block, not directly
     * to the try merge — epilogue must follow that edge instead of jumping straight to merge.
     */
    private static function finallyNormalFallthroughBb(Context $context, TryCatchHandler $handler): ?BasicBlock
    {
        $mergeBb = $context->scope->blockStorage[$handler->mergeBlock] ?? null;
        $finallyCfg = $handler->finallyOp->block1 ?? null;
        if (null === $finallyCfg) {
            return $mergeBb;
        }
        $trail = self::tryBodyTrailingJumpTarget($finallyCfg);
        if (null === $trail || $trail === $handler->mergeBlock) {
            return $mergeBb;
        }

        return $context->scope->blockEntryStorage[$trail]
            ?? $context->scope->blockStorage[$trail]
            ?? $mergeBb;
    }

    public static function findFinallyOp(Block $handlerBlock, int $afterTryIndex): ?OpCode
    {
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $next = $handlerBlock->opCodes[$j];
            if (OpCode::TYPE_CATCH === $next->type) {
                continue;
            }
            if (OpCode::TYPE_FINALLY === $next->type) {
                return $next;
            }
            break;
        }

        return null;
    }

    /**
     * Defer LLVM return through an active try/finally handler (#4246).
     */
    public static function deferReturnIfNeeded(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block,
        bool $isVoid,
        ?Variable $returnVar
    ): bool {
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        // Catch-arm lowering detaches from handlerStack so throws use outer handlers;
        // return-through-finally still needs this try's finally (#24105).
        if (null === $handler || null === $handler->finallyOp) {
            $handler = $context->tryCatch->returnFinallyStack[array_key_last($context->tryCatch->returnFinallyStack)] ?? null;
        }
        if (null === $handler || null === $handler->finallyOp) {
            return false;
        }
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $finallyBb = self::finallyBbFor($jit, $func, $context, $handler, [], false);
        JitReturnPending::setPending($context, $returnVar, $isVoid);
        $builder = $context->builder;
        $returnBlock = $builder->getInsertBlock();
        if (null === $returnBlock || null !== $returnBlock->getTerminator()) {
            $returnBlock = self::appendBlock($func, 'return_defer_finally_'.self::blockSuffix($handler));
            $builder->positionAtEnd($returnBlock);
        } else {
            $builder->positionAtEnd($returnBlock);
        }
        $builder->branch($finallyBb);

        return true;
    }

    /**
     * Defer JUMP/JUMPIF leave (break/continue/goto) through try/finally (#35547 / peer VM #25240).
     *
     * Merge leaves only need {@see finallyBb} — the epilogue already resumes the merge.
     * Non-merge leaves (break) store a pending id and resume via {@see leaveDispatchBb}.
     */
    public static function deferLeaveIfNeeded(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $fromCfg,
        Block $targetCfg,
        BasicBlock $targetLlvm
    ): bool {
        $handler = self::activeFinallyHandler($context);
        if (null === $handler || !self::shouldUnwindLeave($handler, $fromCfg, $targetCfg)) {
            return false;
        }
        $builder = $context->builder;
        $leaveBlock = $builder->getInsertBlock();
        if (null === $leaveBlock || null !== $leaveBlock->getTerminator()) {
            $leaveBlock = self::appendBlock($func, 'leave_defer_finally_'.self::blockSuffix($handler));
            $builder->positionAtEnd($leaveBlock);
        } else {
            $builder->positionAtEnd($leaveBlock);
        }
        self::emitLeaveThroughFinally($jit, $context, $func, $handler, $targetCfg, $targetLlvm);

        return true;
    }

    /**
     * Trampoline BB for a JUMPIF edge that must run finally before {@see $targetLlvm} (#35547).
     *
     * @return BasicBlock|null trampoline entry, or null when the edge stays inside the try body
     */
    public static function leaveEdgeTrampoline(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $fromCfg,
        Block $targetCfg,
        BasicBlock $targetLlvm
    ): ?BasicBlock {
        $handler = self::activeFinallyHandler($context);
        if (null === $handler || !self::shouldUnwindLeave($handler, $fromCfg, $targetCfg)) {
            return null;
        }
        $tramp = self::appendBlock($func, 'leave_finally_edge_'.self::blockSuffix($handler));
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($tramp);
        self::emitLeaveThroughFinally($jit, $context, $func, $handler, $targetCfg, $targetLlvm);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $tramp;
    }

    private static function activeFinallyHandler(Context $context): ?TryCatchHandler
    {
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null === $handler || null === $handler->finallyOp) {
            $handler = $context->tryCatch->returnFinallyStack[array_key_last($context->tryCatch->returnFinallyStack)] ?? null;
        }
        if (null === $handler || null === $handler->finallyOp) {
            return null;
        }

        return $handler;
    }

    /**
     * Mirror VM {@see \PHPCompiler\VM::beginGotoFinallyUnwind} leave detection (#25240).
     *
     * Only edges that originate inside the try body (not merge/finally fallthrough) unwind.
     * Merge→loop JUMP must not re-enter finally or AOT spins forever (#35547).
     */
    private static function shouldUnwindLeave(TryCatchHandler $handler, Block $from, Block $target): bool
    {
        $finallyCfg = $handler->finallyOp->block1 ?? null;
        if (null === $finallyCfg) {
            return false;
        }
        if ($from === $handler->mergeBlock || $from === $finallyCfg) {
            return false;
        }
        $tryBody = $handler->tryBodyBlock;
        if (null === $tryBody) {
            return false;
        }
        if ($from !== $tryBody && !self::blockIsInsideActiveTryBody($handler, $from)) {
            return false;
        }
        if ($target === $finallyCfg) {
            return false;
        }
        if ($target === $handler->mergeBlock) {
            return true;
        }
        if (self::blockIsInsideActiveTryBody($handler, $target)) {
            return false;
        }

        return true;
    }

    /** Target-only leave check for outer-finally chaining after an inner leave (#25240 nested). */
    private static function shouldUnwindLeaveTarget(TryCatchHandler $handler, Block $target): bool
    {
        $finallyCfg = $handler->finallyOp->block1 ?? null;
        if (null === $finallyCfg) {
            return false;
        }
        if ($target === $finallyCfg) {
            return false;
        }
        if ($target === $handler->mergeBlock) {
            return true;
        }
        if (self::blockIsInsideActiveTryBody($handler, $target)) {
            return false;
        }

        return true;
    }

    private static function blockIsInsideActiveTryBody(TryCatchHandler $handler, Block $target): bool
    {
        $entry = $handler->tryBodyBlock;
        if (null === $entry) {
            return false;
        }
        $merge = $handler->mergeBlock;
        $finallyBlock = $handler->finallyOp->block1 ?? null;
        if ($target === $entry) {
            return true;
        }
        if ($target === $merge || $target === $finallyBlock) {
            return false;
        }
        $leaveBlocked = [];
        $leaveBlocked[spl_object_id($merge)] = true;
        if (null !== $finallyBlock) {
            $leaveBlocked[spl_object_id($finallyBlock)] = true;
        }
        if (!self::blockCanReach($entry, $target, $leaveBlocked)) {
            return false;
        }
        if (self::blockCanReach($target, $merge, [])) {
            return true;
        }
        if (null !== $finallyBlock && self::blockCanReach($target, $finallyBlock, [])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, true> $blocked
     */
    private static function blockCanReach(Block $from, Block $to, array $blocked): bool
    {
        if ($from === $to) {
            return true;
        }
        $seen = [];
        $queue = [$from];
        while ([] !== $queue) {
            /** @var Block $block */
            $block = array_pop($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach (self::blockBranchTargets($block) as $succ) {
                if ($succ === $to) {
                    return true;
                }
                if (isset($blocked[spl_object_id($succ)])) {
                    continue;
                }
                $queue[] = $succ;
            }
        }

        return false;
    }

    /** @return list<Block> */
    private static function blockBranchTargets(Block $block): array
    {
        $targets = [];
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2, $op->block3] as $t) {
                if ($t instanceof Block) {
                    $targets[] = $t;
                }
            }
        }

        return $targets;
    }

    private static function emitLeaveThroughFinally(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        TryCatchHandler $handler,
        Block $targetCfg,
        BasicBlock $targetLlvm
    ): void {
        $finallyBb = self::finallyBbFor($jit, $func, $context, $handler, [], false);
        self::ensureFinallyLowering($jit, $func, $context, $handler, []);
        $builder = $context->builder;
        // Always record the leave target (including merge). Merge-only leaves used to
        // skip leaveDispatch, so nested outer finally never ran on continue (#35547).
        $id = self::registerLeaveTarget($context, $func, $handler, $targetCfg, $targetLlvm);
        $slot = self::ensureLeavePendingSlot($context, $func, $handler);
        $i32 = $context->getTypeFromString('int32');
        $builder->store($i32->constInt($id, false), $slot);
        $builder->branch($finallyBb);
    }

    private static function ensureLeavePendingSlot(
        Context $context,
        Function_ $func,
        TryCatchHandler $handler
    ): Value {
        if (null !== $handler->leavePendingSlot) {
            return $handler->leavePendingSlot;
        }
        $i32 = $context->getTypeFromString('int32');
        $handler->leavePendingSlot = BasicBlockHelper::entryAllocaForFunction($context, $func, $i32);

        return $handler->leavePendingSlot;
    }

    private static function registerLeaveTarget(
        Context $context,
        Function_ $func,
        TryCatchHandler $handler,
        Block $targetCfg,
        BasicBlock $targetLlvm
    ): int {
        $cfgId = spl_object_id($targetCfg);
        if (isset($handler->leaveTargetIds[$cfgId])) {
            return $handler->leaveTargetIds[$cfgId];
        }
        $id = $handler->nextLeaveId++;
        $handler->leaveTargetIds[$cfgId] = $id;
        $handler->leaveTargets[$id] = ['cfg' => $targetCfg, 'llvm' => $targetLlvm];
        self::ensureLeavePendingSlot($context, $func, $handler);
        // Dispatch is built once after the try body is lowered (#35547) — rebuilding
        // per target left stale loads in the BB (second load always saw 0 → merge).

        return $id;
    }

    private static function rebuildLeaveDispatch(
        Context $context,
        Function_ $func,
        TryCatchHandler $handler
    ): void {
        if ($handler->leaveDispatchBuilt) {
            return;
        }
        $handler->leaveDispatchBuilt = true;
        if ([] === $handler->leaveTargets) {
            return;
        }
        $dispatch = $handler->leaveDispatchBb;
        if (null === $dispatch) {
            $dispatch = self::appendBlock($func, 'try_leave_dispatch_'.self::blockSuffix($handler));
            $handler->leaveDispatchBb = $dispatch;
        } else {
            // Placeholder from epilogue is a single `br merge` — replace once (#35547).
            $term = $dispatch->getTerminator();
            if (null !== $term) {
                try {
                    $term->eraseFromParent();
                } catch (\Throwable) {
                }
            }
        }
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($dispatch);
        $slot = self::ensureLeavePendingSlot($context, $func, $handler);
        $i32 = $context->getTypeFromString('int32');
        $idVal = $builder->load($slot);
        $builder->store($i32->constInt(0, false), $slot);
        $mergeBb = $context->scope->blockStorage[$handler->mergeBlock] ?? null;
        $cursor = $dispatch;
        foreach ($handler->leaveTargets as $leaveId => $info) {
            $matchBb = self::appendBlock($func, 'try_leave_match_'.$leaveId.'_'.self::blockSuffix($handler));
            $nextBb = self::appendBlock($func, 'try_leave_next_'.$leaveId.'_'.self::blockSuffix($handler));
            $builder->positionAtEnd($cursor);
            $isMatch = $builder->icmp(Builder::INT_EQ, $idVal, $i32->constInt((int) $leaveId, false));
            $builder->branchIf($isMatch, $matchBb, $nextBb);
            $builder->positionAtEnd($matchBb);
            self::emitLeaveResumeOrOuterFinally(
                $context,
                $func,
                $handler,
                $info['cfg'],
                $info['llvm']
            );
            $cursor = $nextBb;
        }
        $builder->positionAtEnd($cursor);
        $fallthroughBb = self::finallyNormalFallthroughBb($context, $handler)
            ?? ($context->scope->blockStorage[$handler->mergeBlock] ?? null);
        if (null !== $fallthroughBb) {
            $builder->branch($fallthroughBb);
        } else {
            $builder->returnVoid();
        }
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
    }

    /**
     * After an inner finally, run an outer finally for the same pending leave when needed (#25240 nested).
     */
    private static function emitLeaveResumeOrOuterFinally(
        Context $context,
        Function_ $func,
        TryCatchHandler $inner,
        Block $targetCfg,
        BasicBlock $targetLlvm
    ): void {
        $builder = $context->builder;
        $stack = $context->tryCatch->handlerStack;
        $outer = null;
        $n = \count($stack);
        for ($i = $n - 1; $i >= 0; --$i) {
            if ($stack[$i] === $inner) {
                if ($i > 0) {
                    $candidate = $stack[$i - 1];
                    if (null !== $candidate->finallyOp && self::shouldUnwindLeaveTarget($candidate, $targetCfg)) {
                        $outer = $candidate;
                    }
                }
                break;
            }
        }
        if (null === $outer) {
            // Also walk returnFinallyStack for catch-detached nesting.
            $retStack = $context->tryCatch->returnFinallyStack;
            $rn = \count($retStack);
            for ($i = $rn - 1; $i >= 0; --$i) {
                if ($retStack[$i] === $inner && $i > 0) {
                    $candidate = $retStack[$i - 1];
                    if (null !== $candidate->finallyOp && self::shouldUnwindLeaveTarget($candidate, $targetCfg)) {
                        $outer = $candidate;
                    }
                    break;
                }
            }
        }
        if (null === $outer || null === $outer->finallyBb) {
            $builder->branch($targetLlvm);

            return;
        }
        if ($targetCfg === $outer->mergeBlock) {
            $builder->branch($outer->finallyBb);

            return;
        }
        $id = self::registerLeaveTarget($context, $func, $outer, $targetCfg, $targetLlvm);
        $slot = self::ensureLeavePendingSlot($context, $func, $outer);
        $i32 = $context->getTypeFromString('int32');
        $builder->store($i32->constInt($id, false), $slot);
        $builder->branch($outer->finallyBb);
    }

    public static function beginTry(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $handlerBlock,
        OpCode $tryOp,
        int $tryOpcodeIndex,
        array $args
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $mergeBlock = $tryOp->block2;
        if (null === $mergeBlock) {
            throw new \LogicException('TYPE_TRY requires merge block (block2)');
        }
        $arms = self::collectCatchOps($handlerBlock, $tryOpcodeIndex);
        $handler = new TryCatchHandler($mergeBlock, $arms);
        $handler->finallyOp = self::findFinallyOp($handlerBlock, $tryOpcodeIndex);
        $handler->tryBodyBlock = $tryOp->block1;
        $handler->postTryOpcodesRemaining = self::countPostTryOpcodes($handlerBlock, $tryOpcodeIndex);
        $context->tryCatch->handlerStack[] = $handler;
        $context->tryCatch->mergeHandlers[spl_object_id($mergeBlock)] = $handler;

        $builder = $context->builder;
        $branchBlock = null;
        try {
            $branchBlock = $builder->getInsertBlock();
        } catch (\Throwable) {
        }
        if (null === $branchBlock || null !== $branchBlock->getTerminator()) {
            $cached = $context->scope->blockStorage[$handlerBlock] ?? null;
            if (null !== $cached && null === $cached->getTerminator()) {
                $branchBlock = $cached;
            } elseif (null === $branchBlock || null !== $branchBlock->getTerminator()) {
                $branchBlock = self::appendBlock($func, 'try_branch_'.self::blockSuffix($handler));
            }
        }
        if (null === $branchBlock) {
            throw new \LogicException('TYPE_TRY lowering requires an active LLVM basic block');
        }
        $context->scope->blockStorage[$handlerBlock] = $branchBlock;
        $builder->positionAtEnd($branchBlock);
        $builder->call($context->lookupFunction('phpc_jit_clear_throw_pending'));
        $builder->call($context->lookupFunction('phpc_jit_clear_return_pending'));
        if (null !== $handler->finallyOp) {
            $leaveSlot = self::ensureLeavePendingSlot($context, $func, $handler);
            $i32 = $context->getTypeFromString('int32');
            $builder->store($i32->constInt(0, false), $leaveSlot);
        }
        $mergeHeaderBb = $context->scope->blockStorage[$mergeBlock] ?? null;
        if (!$handler->mergeBodyCompiled) {
            if (null === $mergeHeaderBb) {
                $mergeHeaderBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
            }
            $context->scope->blockStorage[$mergeBlock] = $mergeHeaderBb;
            $context->scope->blockEntryStorage[$mergeBlock] = $mergeHeaderBb;
            $mergeBodyBb = self::appendBlock($func, 'try_merge_body_'.self::blockSuffix($handler));
            $handler->mergeBodyLlvmBb = $mergeBodyBb;
            if (null === $mergeBodyBb->getTerminator()) {
                // Detach this try while lowering the merge: php-cfg puts a following
                // sibling try/catch in the same end block (#4041 / #23930). If this
                // handler stays on the throw stack, the nested try is compiled as an
                // inner EH region and the outer catch body is skipped / sees the wrong
                // exception at runtime.
                $savedThrowHandlerStack = $context->tryCatch->handlerStack;
                self::detachHandlerFromThrowStack($context, $handler);
                try {
                    $jit->compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBodyBb);
                } finally {
                    $context->tryCatch->handlerStack = $savedThrowHandlerStack;
                }
            }
            $builder->positionAtEnd($mergeHeaderBb);
            if (null === $mergeHeaderBb->getTerminator()) {
                $builder->branch($mergeBodyBb);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'try_merge_after_compile');
            $handler->mergeBodyCompiled = true;
        } elseif (null === $mergeHeaderBb) {
            $mergeHeaderBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
            $context->scope->blockStorage[$mergeBlock] = $mergeHeaderBb;
            $context->scope->blockEntryStorage[$mergeBlock] = $mergeHeaderBb;
        }
        $mergeBb = $mergeHeaderBb;
        $builder->positionAtEnd($branchBlock);
        // Pin finally BB before dispatch so catch arms can branch to it, but compile
        // the finally body only after dispatch exists — epilogue used to call
        // dispatchBbFor mid-finally and rebuild catch wiring via wrapper !== (#24105).
        if (null !== $handler->finallyOp) {
            self::finallyBbFor($jit, $func, $context, $handler, $args, false);
        }
        $handler->dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        if (null !== $handler->finallyOp) {
            self::ensureFinallyLowering($jit, $func, $context, $handler, $args);
        }
        self::emitMergeEntryCheck($jit, $func, $context, $mergeBlock, $mergeBb, $args);
        $savedTrySynthetic = $tryOp->block1->syntheticCfgBranch;
        $tryOp->block1->syntheticCfgBranch = true;
        // Prefer the live LLVM tail from compileSubBlock: property-store guards
        // (DynamicObjectReadonlyGuard / ReadonlyClassGuard) terminate the original
        // blockStorage[tryBody] entry, so sealing that entry alone leaves the real
        // continuation unterminated and sealFunction later emits `ret void` — AOT
        // then exits after a successful `$obj->prop = …` inside try (#28078).
        $tryTail = $jit->compileSubBlock($func, $tryOp->block1, ...$args);
        $tryOp->block1->syntheticCfgBranch = $savedTrySynthetic;
        if (null !== $handler->finallyOp && [] !== $handler->leaveTargets) {
            self::rebuildLeaveDispatch($context, $func, $handler);
        }
        $elseExit = self::tryBodyTrailingJumpTarget($tryOp->block1);
        $elseEntryBb = null;
        if (null !== $elseExit && $elseExit !== $mergeBlock) {
            $elseEntryBb = $context->scope->blockEntryStorage[$elseExit]
                ?? $context->scope->blockStorage[$elseExit]
                ?? null;
            if (null === $elseEntryBb) {
                $elseEntryBb = self::appendBlock($func, 'try_else_'.self::blockSuffix($handler));
                // Entry only — blockStorage is set when opcodes are lowered (#19128).
                $context->scope->blockEntryStorage[$elseExit] = $elseEntryBb;
            }
        }
        $tryLlvm = $tryTail;
        if (null === $tryLlvm || null !== $tryLlvm->getTerminator()) {
            $tryLlvm = $context->scope->blockStorage[$tryOp->block1] ?? null;
        }
        if (null === $tryLlvm || null !== $tryLlvm->getTerminator()) {
            $tryLlvm = BasicBlockHelper::tryGetInsertBlock($context);
        }
        if (null !== $tryLlvm && null === $tryLlvm->getTerminator()) {
            $builder->positionAtEnd($tryLlvm);
            if (null !== $handler->finallyBb) {
                $builder->branch($handler->finallyBb);
            } elseif (null !== $elseEntryBb) {
                $builder->branch($elseEntryBb);
            } elseif (null !== $handler->mergeEntryBb) {
                $builder->branch($handler->mergeEntryBb);
            }
        }
        $tryEntry = $context->scope->blockStorage[$tryOp->block1];
        $builder->positionAtEnd($branchBlock);
        if (0 === $context->inlineIncludeDepth) {
            $context->freeDeadVariables($func, $branchBlock, $handlerBlock);
        }
        if (null === $branchBlock->getTerminator()) {
            $builder->branch($tryEntry);
        }
        // try/finally rewrites the try-body JUMP to the finally CFG (#2114); that is
        // not a try-else arm — recompiling it here would fight ensureFinallyLowering (#24105).
        $finallyCfg = $handler->finallyOp->block1 ?? null;
        if (
            null !== $elseExit
            && null !== $elseEntryBb
            && $elseExit !== $mergeBlock
            && $elseExit !== $finallyCfg
        ) {
            $savedInsert = $builder->getInsertBlock();
            $savedElseSynthetic = $elseExit->syntheticCfgBranch;
            $elseExit->syntheticCfgBranch = true;
            $builder->positionAtEnd($elseEntryBb);
            $jit->compileIncludedAtEntry($func, $elseExit, $elseEntryBb);
            $elseExit->syntheticCfgBranch = $savedElseSynthetic;
            $elseTail = $builder->getInsertBlock();
            if (null !== $elseTail && null === $elseTail->getTerminator() && null !== $handler->mergeEntryBb) {
                $builder->positionAtEnd($elseTail);
                $builder->branch($handler->mergeEntryBb);
            }
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            }
        }
        // TYPE_TRY returns from compileBlockInternal before sibling TYPE_FINALLY/CATCH
        // opcodes run finishPostTryOpcode — without an explicit pop, handlers accumulate
        // and a later try sees the prior one as a false "outer" finally (#35547).
        self::popHandler($context);
    }

    /**
     * Register try/catch dispatch for generator resume segments (#4069).
     *
     * Try body opcodes are compiled by {@see GeneratorHelper::compileYieldPrefix}; this only
     * wires merge/dispatch blocks and branches into the try-body LLVM entry.
     */
    public static function beginTryGeneratorResume(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $handlerBlock,
        OpCode $tryOp,
        int $tryOpcodeIndex,
        array $args,
        BasicBlock $branchBlock,
        BasicBlock $tryBodyEntryBb
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        // Mirror beginTry: dispatchBbFor clears return-pending (#35008). Always-on
        // JitReturnPending registration was removed in #34621, so generator resume
        // must link the ABI before catch dispatch — otherwise thin AOT aborts with
        // "Unable to lookup non-existing function phpc_jit_clear_return_pending".
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $mergeBlock = $tryOp->block2;
        if (null === $mergeBlock) {
            throw new \LogicException('TYPE_TRY requires merge block (block2)');
        }
        $arms = self::collectCatchOps($handlerBlock, $tryOpcodeIndex);
        $handler = new TryCatchHandler($mergeBlock, $arms);
        $handler->finallyOp = self::findFinallyOp($handlerBlock, $tryOpcodeIndex);
        $handler->tryBodyBlock = $tryOp->block1;
        $handler->postTryOpcodesRemaining = self::countPostTryOpcodes($handlerBlock, $tryOpcodeIndex);
        $context->tryCatch->handlerStack[] = $handler;
        $context->tryCatch->mergeHandlers[spl_object_id($mergeBlock)] = $handler;

        $builder = $context->builder;
        $context->scope->blockStorage[$handlerBlock] = $branchBlock;
        $builder->positionAtEnd($branchBlock);
        $mergeBb = $context->scope->blockStorage[$mergeBlock] ?? null;
        if (null === $mergeBb) {
            $mergeBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
        }
        // Mirror beginTry: publish merge into blockStorage + mergeBodyLlvmBb *before*
        // buildDispatch. Skipping that left catch-without-yield arms with no merge
        // target → clear_throw + unreachable → AOT SIGSEGV after catch echo (#33726 /
        // re-#27518). Catch-with-yield still uses gen_catch_resume_* and ignores merge.
        //
        // Do not compileIncludedAtEntry the merge into the i64 resume fn — RETURN
        // lowers as `ret %__value__` and fails module verify. Empty post-try merge
        // (Terminal_Return only) closes the generator like gen_done; post-try yields
        // after catch are separate resume points / follow-up.
        if (!$handler->mergeBodyCompiled) {
            $context->scope->blockStorage[$mergeBlock] = $mergeBb;
            $context->scope->blockEntryStorage[$mergeBlock] = $mergeBb;
            $mergeBodyBb = self::appendBlock($func, 'try_merge_body_'.self::blockSuffix($handler));
            $handler->mergeBodyLlvmBb = $mergeBodyBb;
            $builder->positionAtEnd($mergeBodyBb);
            if (null !== $context->generatorStateParam) {
                self::emitGeneratorResumeComplete($context);
            } else {
                $builder->returnValue($context->getTypeFromString('int64')->constInt(0, false));
            }
            $builder->positionAtEnd($mergeBb);
            if (null === $mergeBb->getTerminator()) {
                $builder->branch($mergeBodyBb);
            }
            $handler->mergeBodyCompiled = true;
        }
        $handler->dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        self::emitMergeEntryCheck($jit, $func, $context, $mergeBlock, $mergeBb, $args);
        if (null !== $tryOp->block1) {
            $context->scope->blockStorage[$tryOp->block1] = $tryBodyEntryBb;
        }
        $builder->positionAtEnd($branchBlock);
        $builder->branch($tryBodyEntryBb);
    }

    /**
     * Empty post-try merge after a generator catch: mark done and return from resume
     * (Zend closes the generator when catch falls off the end without yielding) (#33726).
     */
    private static function emitGeneratorResumeComplete(Context $context): void
    {
        $stateParam = $context->generatorStateParam;
        if (null === $stateParam) {
            throw new \LogicException('emitGeneratorResumeComplete requires generatorStateParam');
        }
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $builder = $context->builder;
        $builder->store($i1->constInt(1, false), $builder->structGep($stateParam, $map['has_returned']));
        $builder->store($i1->constInt(1, false), $builder->structGep($stateParam, $map['done']));
        $builder->store($i1->constInt(0, false), $builder->structGep($stateParam, $map['has_current']));
        $builder->returnValue($i64->constInt(0, false));
    }

    /**
     * Uncaught throw from generator body during resume: close (not returned) + pend (#34455).
     *
     * php-src: Zend/zend_generators.c — zend_generator_close after body exception.
     */
    private static function emitGeneratorResumeBodyThrow(Context $context, Value $excObj): void
    {
        $stateParam = $context->generatorStateParam;
        if (null === $stateParam) {
            throw new \LogicException('emitGeneratorResumeBodyThrow requires generatorStateParam');
        }
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $builder = $context->builder;
        $builder->store($i1->constInt(1, false), $builder->structGep($stateParam, $map['done']));
        $builder->store($i1->constInt(0, false), $builder->structGep($stateParam, $map['has_current']));
        $builder->store($i1->constInt(0, false), $builder->structGep($stateParam, $map['has_returned']));
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $excObj);
        $builder->returnValue($i64->constInt(0, false));
    }

    public static function emitMergeEntryCheck(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $mergeCfgBlock,
        BasicBlock $mergeBb,
        array $args
    ): void {
        $handler = $context->tryCatch->mergeHandlers[spl_object_id($mergeCfgBlock)] ?? null;
        if (null === $handler || $handler->mergeEntryEmitted) {
            return;
        }
        $handler->mergeEntryEmitted = true;
        $dispatchBb = $handler->dispatchBb;
        if (null === $dispatchBb) {
            $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        }

        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $entryBb = self::appendBlock($func, 'try_merge_entry_'.self::blockSuffix($handler));
        $handler->mergeEntryBb = $entryBb;
        $builder->positionAtEnd($entryBb);
        $hasPending = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
        $i32 = $context->getTypeFromString('int32');
        $hasBool = $builder->icmp(
            Builder::INT_NE,
            $hasPending,
            $i32->constInt(0, false)
        );
        $builder->branchIf($hasBool, $dispatchBb, $mergeBb);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
    }

    /**
     * After a callee that may have set throw-pending (e.g. Enum::from ValueError),
     * branch into the active try handler or abort via the type-error pending buffer (#24219).
     */
    public static function emitCheckPendingThrowAfterCall(Context $context): void
    {
        // NestedJIT helper compiles also invoke calls; do not splice try/catch edges there (#24219).
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $builder = $context->builder;
        $insert = self::probeInsertBlock($builder);
        if (null === $insert || null !== $insert->getTerminator()) {
            return;
        }
        $func = $insert->getParent();
        if (!$func instanceof Function_) {
            return;
        }

        $handler = self::resolveThrowHandler($context);
        if (null !== $handler && null !== $handler->dispatchBb) {
            $dispatchParent = $handler->dispatchBb->getParent();
            // Skip leaked resume-function handlers — cross-function br fails verify (#27518).
            if (
                !$dispatchParent instanceof Function_
                || !self::sameLlvmFunction($func, $dispatchParent)
            ) {
                $handler = null;
            }
        }
        if (null !== $handler && null !== $handler->dispatchBb) {
            $i32 = $context->getTypeFromString('int32');
            $hasPending = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
            $hasBool = $builder->icmp(
                Builder::INT_NE,
                $hasPending,
                $i32->constInt(0, false)
            );
            $cont = self::appendBlock($func, 'after_call_no_throw');
            $pending = self::appendBlock($func, 'after_call_throw_pending');
            $builder->branchIf($hasBool, $pending, $cont);
            $builder->positionAtEnd($pending);
            // Drop type-error abort buffer so standalone main does not re-fatal (#24219).
            if (null !== $context->module->getNamedFunction('phpc_jit_type_error_clear_pending')) {
                $builder->call($context->lookupFunction('phpc_jit_type_error_clear_pending'));
            }
            $builder->branch($handler->dispatchBb);
            $builder->positionAtEnd($cont);

            return;
        }

        // Uncaught: Enum::from also fills the type-error pending message for abort text.
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType
            && null !== $context->module->getNamedFunction('phpc_jit_abort_if_pending_type_error')
        ) {
            $builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        }
    }

    /**
     * Pend a TypeError object for the caller's after-call check (no local try) (#26486).
     *
     * Mirrors {@see \PHPCompiler\JIT\Builtin\BackedEnumFromRuntime} cross-function ValueError:
     * object pending for try/catch; pair with {@see TypeErrorRaise::emitRaise} for uncaught abort.
     */
    public static function emitPendTypeErrorForCaller(Context $context, string $message): void
    {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitThrow::ensureStandaloneBodies($context);
        }

        $object = $context->type->object;
        $classId = $object->lookup('TypeError');
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        // Store on Error — TypeError inherits message; declaring on TypeError can miss getMessage (#26486).
        $object->storeInstanceProperty($obj, 'Error', ExceptionSupport::PROP_MESSAGE, $msgVar);
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
    }

    /**
     * Pend an Error object for the caller's after-call check (no local try) (#31901).
     *
     * Same shape as {@see emitPendTypeErrorForCaller}: static-method FETCH_THIS Errors
     * must propagate to `{main}` try/catch under AOT, not abort inside the callee.
     */
    public static function emitPendErrorForCaller(Context $context, string $message): void
    {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitThrow::ensureStandaloneBodies($context);
        }

        $object = $context->type->object;
        GetClassRuntime::ensureLinked($context);
        $object->lookup('Error');
        $classId = $object->lookup('Error');
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        $object->storeInstanceProperty($obj, 'Error', ExceptionSupport::PROP_MESSAGE, $msgVar);
        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
    }

    /**
     * Public wrapper for {@see emitPropagateReturn} after cross-function pending throw (#26486).
     */
    public static function emitPropagateReturnAfterPendingThrow(Context $context, Function_ $func): void
    {
        self::emitPropagateReturn($context, $func);
    }

    /**
     * Raise a catchable Error inside an active try block (asymmetric visibility #4029).
     */
    public static function emitCatchableErrorMessage(
        Context $context,
        \PHPCompiler\JIT $jit,
        string $message
    ): void {
        self::emitCatchableClassError($context, 'Error', $message, $jit);
    }

    public static function emitCatchableClassError(
        Context $context,
        string $className,
        string $message,
        ?\PHPCompiler\JIT $jit = null,
        string $file = '',
        int $line = 0,
        int $code = 0
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $insert) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'catchable_error_resume');
            $insert = BasicBlockHelper::tryGetInsertBlock($context);
        }
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }
        if (null === $insert) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }
        $func = $insert->getParent();
        assert($func instanceof Function_);
        $dispatchBb = null !== $jit
            ? self::dispatchBbFor($jit, $func, $context, $handler, [])
            : $handler->dispatchBb;
        if (null === $dispatchBb) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }

        $object = $context->type->object;
        // Parent chain before allocate — catch may already be lowered (#27107 / #27106).
        GetClassRuntime::ensureLinked($context);
        $object->lookup($className);
        $classId = $object->lookup($className);
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        $object->storeInstanceProperty($obj, $className, 'message', $msgVar);
        if ($code !== 0) {
            if (!$object->hasProperty($classId, ExceptionSupport::PROP_CODE)) {
                $object->defineProperty($classId, ExceptionSupport::PROP_CODE, Variable::TYPE_NATIVE_LONG);
            }
            $codeVar = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($code)
            );
            $object->storeInstanceProperty($obj, $className, ExceptionSupport::PROP_CODE, $codeVar);
        }

        // Stamp file/line like zend_exception_get_props so getFile()/getLine() work (#24397).
        if ('' === $file) {
            $file = $context->jitAotEntryScriptPath;
        }
        if ('' === $file) {
            $file = 'Unknown';
        }
        if ($line <= 0) {
            $line = max(0, $context->callSiteLine);
        }
        if ($context->evalInlineDepth > 0 && $line > 0) {
            $line = VmEval::unwrapEvalLine($line);
        }
        $fileStr = $context->builder->load($context->constantStringFromString($file));
        $fileVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $fileStr);
        $object->storeInstanceProperty($obj, $className, ExceptionSupport::PROP_FILE, $fileVar);
        $lineVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger(max(0, $line))
        );
        $object->storeInstanceProperty($obj, $className, ExceptionSupport::PROP_LINE, $lineVar);

        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $context->builder->branch($dispatchBb);
    }

    /**
     * Class hint for catch-bound $e so method resolution finds Error/Exception proxies (#27106).
     *
     * @param list<string> $catchTypes lowercase type names from TYPE_CATCH
     */
    private static function catchVariableClassHint(array $catchTypes): string
    {
        if ([] === $catchTypes) {
            return 'Throwable';
        }
        // Intersection `a&b` (#28205): hint from the first member only.
        $lc = $catchTypes[0];
        if (str_contains($lc, '&')) {
            $lc = explode('&', $lc, 2)[0];
        }
        if ('throwable' === $lc) {
            return 'Throwable';
        }
        if ('error' === $lc) {
            return 'Error';
        }
        if ('exception' === $lc) {
            return 'Exception';
        }
        foreach (
            [
                'TypeError', 'ValueError', 'ParseError', 'CompileError',
                'ArgumentCountError', 'UnhandledMatchError', 'ArithmeticError',
                'DivisionByZeroError', 'AssertionError', 'ErrorException',
            ] as $name
        ) {
            if (strtolower($name) === $lc) {
                return $name;
            }
        }

        return 'Throwable';
    }

    /**
     * i1: thrown object matches this catch arm's type list (`A|B`, members may be `A&B`).
     *
     * Uses LLVM class_id instanceof (same path as `instanceof` / #34597). Do not call
     * NestedJIT TryCatchJitHelper here — thin AOT has no active VM context for it.
     *
     * @param list<string> $catchTypes lowercase type names from TYPE_CATCH
     */
    private static function emitCatchTypesMatchI1(
        Context $context,
        Variable $thrownVar,
        array $catchTypes
    ): Value {
        GetClassRuntime::ensureLinked($context);
        $context->type->object->lookup('Throwable');
        $context->type->object->lookup('Error');
        $context->type->object->lookup('Exception');
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($catchTypes as $typeName) {
            if ('' === $typeName) {
                continue;
            }
            if (str_contains($typeName, '&')) {
                $and = $i1->constInt(1, false);
                foreach (explode('&', $typeName) as $part) {
                    if ('' === $part) {
                        continue;
                    }
                    $context->type->object->lookup($part);
                    $check = $context->type->object->emitInstanceOf($thrownVar, $part);
                    $bool = Variable::TYPE_NATIVE_BOOL === $check->type
                        ? $check->value
                        : $context->helper->loadValue($check);
                    $and = $context->builder->and($and, $bool);
                }
                $acc = $context->builder->or($acc, $and);
                continue;
            }
            $context->type->object->lookup($typeName);
            $check = $context->type->object->emitInstanceOf($thrownVar, $typeName);
            $bool = Variable::TYPE_NATIVE_BOOL === $check->type
                ? $check->value
                : $context->helper->loadValue($check);
            $acc = $context->builder->or($acc, $bool);
        }

        return $acc;
    }

    public static function emitRethrow(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $builder = $context->builder;
        $objPtr = $context->getTypeFromString('__object__*');
        $active = $builder->call($context->lookupFunction('phpc_jit_get_active_catch'));
        $isNull = $builder->icmp(
            Builder::INT_EQ,
            $active,
            $objPtr->constNull()
        );
        $throwBlock = self::probeInsertBlock($builder);
        if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
            $throwBlock = self::appendBlock($func, 'rethrow_check');
            $builder->positionAtEnd($throwBlock);
        } else {
            $builder->positionAtEnd($throwBlock);
        }
        $outsideCatch = self::appendBlock($func, 'rethrow_outside_catch');
        $dispatchPath = self::appendBlock($func, 'rethrow_dispatch');
        $builder->branchIf($isNull, $outsideCatch, $dispatchPath);
        $builder->positionAtEnd($outsideCatch);
        $handler = self::resolveThrowHandler($context);
        if (null !== $handler) {
            self::emitCatchableClassError(
                $context,
                'LogicException',
                'Cannot use "throw;" outside of a catch block',
                $jit
            );
        } else {
            ErrorRaise::emitRaise($context, 'Cannot use "throw;" outside of a catch block');
        }
        $builder->positionAtEnd($dispatchPath);
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            $context->freeDeadVariables($func, $builder->getInsertBlock(), $block);
            $builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

            return;
        }
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $active);
        $builder->branch($dispatchBb);
    }

    public static function emitThrow(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block,
        OpCode $op
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            $builder = $context->builder;
            $throwBlock = self::probeInsertBlock($builder);
            if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
                $throwBlock = self::appendBlock($func, 'throw_uncaught');
                $builder->positionAtEnd($throwBlock);
            } else {
                $builder->positionAtEnd($throwBlock);
            }
            // Resolve the throw operand before freeDeadVariables — and pass it as skip —
            // so the Exception object is not freed before instanceof Throwable (#34868).
            $throwOperand = $block->getOperand($op->arg1);
            $context->freeDeadVariables($func, $throwBlock, $block, $throwOperand);
            $thrown = $context->getVariableFromOp($throwOperand);
            if (VMVariable::TYPE_ENUM_CASE === $thrown->type) {
                // AOT standalone: ErrorRaise has no body — allocate Error + UncaughtThrowPrinter (#33975).
                self::emitUncaughtErrorObject(
                    $context,
                    $func,
                    $block,
                    ExceptionSupport::THROW_NON_THROWABLE_MESSAGE
                );

                return;
            }
            if (
                Variable::TYPE_OBJECT !== $thrown->type
                && Variable::TYPE_VALUE !== $thrown->type
            ) {
                self::emitUncaughtErrorObject(
                    $context,
                    $func,
                    $block,
                    ExceptionSupport::THROW_ONLY_OBJECTS_MESSAGE
                );

                return;
            }
            // Seed Throwable/Error before instanceof — uncaught main has no catch-arm seed (#33975).
            GetClassRuntime::ensureLinked($context);
            $context->type->object->lookup('Throwable');
            $context->type->object->lookup('Error');
            $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $thrown, 'Throwable');
            $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
                ? $isThrowable->value
                : $context->helper->loadValue($isThrowable);
            $validThrow = self::appendBlock($func, 'throw_uncaught_valid');
            $invalidThrow = self::appendBlock($func, 'throw_uncaught_non_throwable');
            $builder->branchIf($isBool, $validThrow, $invalidThrow);
            $builder->positionAtEnd($invalidThrow);
            self::emitUncaughtErrorObject(
                $context,
                $func,
                $block,
                ExceptionSupport::THROW_NON_THROWABLE_MESSAGE
            );
            $builder->positionAtEnd($validThrow);
            $obj = self::loadThrownObject($context, $thrown);
            ExceptionThrowToStringSeed::seed($context, $obj, $block);
            if (null !== $context->generatorStateParam) {
                // Body throw during resume: close generator (not has_returned) and pend for
                // the caller — Zend zend_generator_close on uncaught body throw (#34455).
                self::emitGeneratorResumeBodyThrow($context, $obj);
            } elseif (self::isNonMainUserFunction($block)) {
                $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
                self::emitPropagateReturn($context, $func);
            } else {
                self::emitUncaughtUserHandlerOrAbort($context, $obj);
            }

            return;
        }
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);

        $thrown = $context->getVariableFromOp($block->getOperand($op->arg1));
        if (
            Variable::TYPE_OBJECT !== $thrown->type
            && Variable::TYPE_VALUE !== $thrown->type
            && VMVariable::TYPE_ENUM_CASE !== $thrown->type
        ) {
            self::emitCatchableClassError(
                $context,
                \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
                \PHPCompiler\VM\ExceptionSupport::THROW_ONLY_OBJECTS_MESSAGE,
                $jit
            );

            return;
        }

        $builder = $context->builder;
        $throwBlock = self::probeInsertBlock($builder);
        if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
            $throwBlock = self::appendBlock($func, 'throw_pending_'.self::blockSuffix($handler));
            $builder->positionAtEnd($throwBlock);
        } else {
            $builder->positionAtEnd($throwBlock);
        }

        // Seed Throwable/Error before instanceof when catch arms omitted them (#33975).
        GetClassRuntime::ensureLinked($context);
        $context->type->object->lookup('Throwable');
        $context->type->object->lookup('Error');
        $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $thrown, 'Throwable');
        $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
            ? $isThrowable->value
            : $context->helper->loadValue($isThrowable);
        $validThrow = self::appendBlock($func, 'throw_valid_'.self::blockSuffix($handler));
        $invalidThrow = self::appendBlock($func, 'throw_non_throwable_'.self::blockSuffix($handler));
        $builder->branchIf($isBool, $validThrow, $invalidThrow);
        $builder->positionAtEnd($invalidThrow);
        self::emitCatchableClassError(
            $context,
            \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
            \PHPCompiler\VM\ExceptionSupport::THROW_NON_THROWABLE_MESSAGE,
            $jit
        );
        $builder->positionAtEnd($validThrow);

        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);
            $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        ExceptionThrowToStringSeed::seed($context, $obj, $block);
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $builder->branch($dispatchBb);
    }

    /**
     * @param list<Variable> $args
     */
    private static function dispatchBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->dispatchBb) {
            $parent = $handler->dispatchBb->getParent();
            // PHPLLVM wraps the same LLVMValueRef in distinct PHP objects; === misses (#24105).
            if ($parent instanceof Function_ && self::sameLlvmFunction($parent, $func)) {
                return $handler->dispatchBb;
            }
            $handler->dispatchBb = null;
        }

        return $handler->dispatchBb = self::buildDispatch($jit, $func, $context, $handler, $args);
    }

    /**
     * True when two Function_ wrappers refer to the same LLVM function (#24105, #27518).
     *
     * Distinct PHPLLVM wrappers around the same {@see \llvm\LLVMValueRef} must compare equal —
     * otherwise {@see emitCheckPendingThrowAfterCall} drops a same-function try handler and
     * Enum::from() ValueError becomes an uncaught fatal again (#27667 / re-#24219). php-llvm's
     * FFI binding keeps the pointer in private {@code $data} (use {@see \llvm\LLVMValueRef::equals}),
     * not a public {@code $cdata} property.
     */
    public static function sameLlvmFunction(Function_ $a, Function_ $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \PHPLLVM\LLVMAbstract\Value
            && $b instanceof \PHPLLVM\LLVMAbstract\Value) {
            $va = $a->value;
            $vb = $b->value;
            if ($va === $vb) {
                return true;
            }
            if ($va instanceof \llvm\LLVMValueRef && $vb instanceof \llvm\LLVMValueRef) {
                return $va->equals($vb);
            }
            // Legacy / alternate FFI shapes that expose a public cdata slot.
            if (is_object($va) && is_object($vb) && isset($va->cdata, $vb->cdata)) {
                return $va->cdata === $vb->cdata;
            }
        }

        return false;
    }

    /**
     * @param list<Variable> $args
     */
    private static function buildDispatch(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->dispatchBb) {
            $existing = $handler->dispatchBb->getParent();
            if ($existing instanceof Function_ && self::sameLlvmFunction($existing, $func)) {
                return $handler->dispatchBb;
            }
        }

        $suffix = self::blockSuffix($handler);
        $dispatch = self::appendBlock($func, 'try_catch_dispatch_'.$suffix);
        // Pin before catch lowering: emitMergeEntryCheck re-enters dispatchBbFor mid-build (#4041).
        $handler->dispatchBb = $dispatch;
        // Catch arms compile before the try body — seed Throwable/Error so getMessage /
        // get_class see full layouts (peer #26854 / #27107, #27106).
        GetClassRuntime::ensureLinked($context);
        // Defensive: generator resume used to reach here before beginTry linked
        // ReturnPending (#35008 / #34621). Idempotent with beginTry*.
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($dispatch);
        $builder->call($context->lookupFunction('phpc_jit_clear_return_pending'));

        $pendingObj = $builder->call($context->lookupFunction('phpc_jit_take_throw_pending'));
        $mergeBody = $context->scope->blockStorage[$handler->mergeBlock] ?? null;

        $uncaught = self::appendBlock($func, 'try_uncaught_'.$suffix);
        $nextCatch = $dispatch;

        foreach ($handler->catchArms as $arm) {
            $catchOp = $arm['op'];
            $types = $arm['catchTypes'];
            // Always lower the catch arm at this dispatch entry (#4041 / #23930).
            // Reusing blockStorage[catch] from an earlier partial compile (e.g. nested
            // try inside the merge of a prior try) skips the body — first catch goes
            // silent and the next catch can observe the earlier exception object.
            $catchBodyBb = self::appendBlock($func, 'try_catch_match_'.$suffix);
            $noMatchBb = self::appendBlock($func, 'try_catch_nomatch_'.$suffix);
            $catchSetupBb = self::appendBlock($func, 'try_catch_setup_'.$suffix);

            $builder->positionAtEnd($nextCatch);
            if ([] === $types) {
                $builder->branch($catchSetupBb);
            } else {
                // LLVM class_id instanceof — NestedJIT TryCatchJitHelper needs an active VM
                // context that thin AOT does not have, so multi-catch never matched and the
                // old single-arm bypass incorrectly caught non-matching types (#34597).
                $thrownVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                $matches = self::emitCatchTypesMatchI1($context, $thrownVar, $types);
                $builder->branchIf($matches, $catchSetupBb, $noMatchBb);
            }

            $builder->positionAtEnd($catchSetupBb);
            $builder->call($context->lookupFunction('phpc_jit_set_active_catch'), $pendingObj);
            // Detach only while lowering the catch arm so throws inside the arm use outer
            // handlers; restore before returning so try-body throw lowering still sees this try
            // (#4886, #10527 — buildDispatch runs before compileSubBlock in beginTry).
            $savedThrowHandlerStack = $context->tryCatch->handlerStack;
            self::detachHandlerFromThrowStack($context, $handler);
            // Keep finally visible to deferReturnIfNeeded while detached (#24105).
            $pushedReturnFinally = false;
            if (null !== $handler->finallyOp) {
                $context->tryCatch->returnFinallyStack[] = $handler;
                $pushedReturnFinally = true;
            }
            try {
            if (null !== $catchOp->arg3) {
                $operand = $catchOp->block1->getOperand((int) $catchOp->arg3);
                // TYPE_CATCH binds before try-body typing; without a class hint,
                // $e->getMessage() lowers as object::… and aborts under thin AOT (#27106).
                $operand->type = new Type(
                    Type::TYPE_OBJECT,
                    [],
                    self::catchVariableClassHint($types)
                );
                $caughtVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                $jit->assignOperandForced($operand, $caughtVar);
            }
            // Generator/fiber catch arms with a dedicated resume entry: branch there only.
            // Branching to catchBodyBb first then again to catchResume put a second terminator
            // on try_catch_setup (#27518 — "Terminator found in the middle of a basic block").
            if ($context->compilingGeneratorResume && null !== $catchOp->block1) {
                $catchResume = $context->generatorCatchDispatchEntry[spl_object_id($catchOp->block1)] ?? null;
                if (null !== $catchResume) {
                    $builder->branch($catchResume);
                    $context->tryCatch->handlerStack = $savedThrowHandlerStack;
                    $nextCatch = $noMatchBb;
                    $builder->positionAtEnd($nextCatch);

                    continue;
                }
            }
            if ($context->compilingFiberResume && null !== $catchOp->block1) {
                $catchResume = $context->fiberCatchDispatchEntry[spl_object_id($catchOp->block1)] ?? null;
                if (null !== $catchResume) {
                    $builder->branch($catchResume);
                    $context->tryCatch->handlerStack = $savedThrowHandlerStack;
                    $nextCatch = $noMatchBb;
                    $builder->positionAtEnd($nextCatch);

                    continue;
                }
            }
            $builder->branch($catchBodyBb);
            $catchTail = $jit->compileCatchArmAtEntry($func, $catchOp->block1, $catchBodyBb, ...$args);
            // Prefer an open insert block — the return value may be a mid-block that already
            // branches to the arm's real tail (#23641 AFTER).
            $openTail = $builder->getInsertBlock();
            if (null !== $openTail && null === $openTail->getTerminator()) {
                $catchTail = $openTail;
            }
            $builder->positionAtEnd($catchTail);
            if (null === $catchTail->getTerminator()) {
                if (null !== $handler->finallyBb) {
                    $builder->call($context->lookupFunction('phpc_jit_clear_throw_pending'));
                    $builder->branch($handler->finallyBb);
                } else {
                    $builder->call($context->lookupFunction('phpc_jit_clear_throw_pending'));
                    $builder->call($context->lookupFunction('phpc_jit_clear_active_catch'));
                    // Prefer the dedicated merge-body BB — blockStorage[merge] may point at a
                    // post-return dead insert from ensureOpenInsertBlock (#23641 AFTER).
                    // mergeEntryBb is still null while buildDispatch runs (created after).
                    $mergeTarget = $handler->mergeBodyLlvmBb
                        ?? $handler->mergeEntryBb
                        ?? $mergeBody;
                    if (null !== $mergeTarget) {
                        $builder->branch($mergeTarget);
                    }
                }
            }
            $context->tryCatch->handlerStack = $savedThrowHandlerStack;
            } finally {
                if ($pushedReturnFinally) {
                    $retStack = &$context->tryCatch->returnFinallyStack;
                    $idx = array_search($handler, $retStack, true);
                    if (false !== $idx) {
                        array_splice($retStack, $idx, 1);
                    }
                }
            }

            $nextCatch = $noMatchBb;
            $builder->positionAtEnd($nextCatch);
        }

        if (null !== $handler->finallyBb) {
            $beforeFinally = self::appendBlock($func, 'try_nomatch_finally_'.$suffix);
            $builder->branch($beforeFinally);
            $builder->positionAtEnd($beforeFinally);
            $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
            $builder->branch($handler->finallyBb);
        } else {
            $builder->branch($uncaught);
            $builder->positionAtEnd($uncaught);
            $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
            self::emitUncaughtUserHandlerOrAbort($context, $pendingObj);
        }

        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $dispatch;
    }

    /**
     * TYPE_CATCH / TYPE_FINALLY after TYPE_TRY are lowered inside beginTry; skip CFG opcodes (#2114).
     */
    public static function finishPostTryOpcode(Context $context): void
    {
        if ([] === $context->tryCatch->handlerStack) {
            return;
        }
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)];
        if ($handler->postTryOpcodesRemaining <= 0) {
            return;
        }
        --$handler->postTryOpcodesRemaining;
        if (0 === $handler->postTryOpcodesRemaining) {
            self::popHandler($context);
        }
    }

    public static function popHandler(Context $context): void
    {
        if ([] === $context->tryCatch->handlerStack) {
            return;
        }
        $handler = array_pop($context->tryCatch->handlerStack);
        unset($context->tryCatch->mergeHandlers[spl_object_id($handler->mergeBlock)]);
        $retStack = &$context->tryCatch->returnFinallyStack;
        $idx = array_search($handler, $retStack, true);
        if (false !== $idx) {
            array_splice($retStack, $idx, 1);
        }
    }

    private static function loadThrownObject(Context $context, Variable $thrown): Value
    {
        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);

            return $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        return $obj;
    }

    /**
     * Uncaught non-Throwable / non-object throw → Zend-shaped Error fatal (#33975).
     *
     * php-src: Zend/zend_exceptions.c — zend_throw_exception_internal rejects non-Throwable.
     * AOT standalone must not use {@see ErrorRaise::emitRaise} alone (no raise body → SIGSEGV).
     */
    private static function emitUncaughtErrorObject(
        Context $context,
        Function_ $func,
        Block $block,
        string $message
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitThrow::ensureStandaloneBodies($context);
        }
        GetClassRuntime::ensureLinked($context);
        $object = $context->type->object;
        $object->lookup(ExceptionSupport::CLASS_ERROR);
        $classId = $object->lookup(ExceptionSupport::CLASS_ERROR);
        $errObj = $object->allocate($classId);
        $object->markObjectConstructed($errObj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
        $object->storeInstanceProperty(
            $errObj,
            ExceptionSupport::CLASS_ERROR,
            ExceptionSupport::PROP_MESSAGE,
            $msgVar
        );
        $file = $context->jitAotEntryScriptPath;
        if ('' === $file) {
            $file = 'Unknown';
        }
        $line = max(0, $context->callSiteLine);
        if ($context->evalInlineDepth > 0 && $line > 0) {
            $line = VmEval::unwrapEvalLine($line);
        }
        $fileStr = $context->builder->load($context->constantStringFromString($file));
        $fileVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $fileStr);
        $object->storeInstanceProperty(
            $errObj,
            ExceptionSupport::CLASS_ERROR,
            ExceptionSupport::PROP_FILE,
            $fileVar
        );
        $lineVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger($line)
        );
        $object->storeInstanceProperty(
            $errObj,
            ExceptionSupport::CLASS_ERROR,
            ExceptionSupport::PROP_LINE,
            $lineVar
        );
        ExceptionThrowToStringSeed::seed($context, $errObj, $block);
        if (self::isNonMainUserFunction($block)) {
            $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $errObj);
            self::emitPropagateReturn($context, $func);
        } else {
            self::emitUncaughtUserHandlerOrAbort($context, $errObj);
        }
    }

    private static function emitUncaughtUserHandlerOrAbort(Context $context, Value $exceptionObj): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ex_handler_cont');
        ExceptionHandlerJitRuntime::ensureLinked($context);
        $builder = $context->builder;
        $i32 = $context->getTypeFromString('int32');
        $handled = $builder->call(
            $context->lookupFunction('__phpc_exception_handler_dispatch'),
            $exceptionObj
        );
        $func = self::probeInsertBlock($builder)?->getParent();
        if (!$func instanceof Function_) {
            throw new \LogicException('emitUncaughtUserHandlerOrAbort requires parent function');
        }
        $handledBb = self::appendBlock($func, 'ex_handler_handled');
        $abortBb = self::appendBlock($func, 'ex_handler_abort');
        // Non-zero = user set_exception_handler consumed the throw (#21325).
        // Empty stack returns 0 → print Zend-shaped fatal + exit(255) (#23641).
        $builder->branchIf(
            $builder->icmp(Builder::INT_NE, $handled, $i32->constInt(0, false)),
            $handledBb,
            $abortBb
        );
        $builder->positionAtEnd($handledBb);
        ScriptExit::emitLibcExitWithStatus($context, $context->getTypeFromString('int64')->constInt(0, false));
        $builder->positionAtEnd($abortBb);
        UncaughtThrowPrinter::emitPrintAndExit($context, $exceptionObj);
        // Do not open a fall-through insert block after exit — that attached main
        // epilogue and produced silent rc=0 (#23641).
    }

    /**
     * Active try handler for throw/rethrow dispatch (innermost unconsumed try).
     */
    public static function resolveThrowHandler(Context $context): ?TryCatchHandler
    {
        if ([] === $context->tryCatch->handlerStack) {
            return null;
        }

        return $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)];
    }

    /**
     * After entering a catch arm, further throws use enclosing try handlers (#4886).
     */
    public static function detachHandlerFromThrowStack(Context $context, TryCatchHandler $handler): void
    {
        $stack = $context->tryCatch->handlerStack;
        $idx = array_search($handler, $stack, true);
        if (false === $idx) {
            return;
        }
        array_splice($context->tryCatch->handlerStack, $idx, 1);
    }

    private static function countPostTryOpcodes(Block $handlerBlock, int $afterTryIndex): int
    {
        $count = 0;
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $type = $handlerBlock->opCodes[$j]->type;
            if (OpCode::TYPE_CATCH === $type || OpCode::TYPE_FINALLY === $type) {
                ++$count;
                continue;
            }
            break;
        }

        return $count;
    }

    /**
     * @param list<Variable> $args
     */
    private static function finallyBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args,
        bool $compileBody
    ): BasicBlock {
        if (null !== $handler->finallyBb) {
            if ($compileBody && !$handler->finallyBodyCompiled) {
                self::compileFinallyBody($jit, $func, $context, $handler, $args);
            }

            return $handler->finallyBb;
        }
        if (null === $handler->finallyOp || null === $handler->finallyOp->block1) {
            throw new \LogicException('finally lowering requested without TYPE_FINALLY');
        }
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $finallyCfg = $handler->finallyOp->block1;
        $mergeHeaderBb = $context->scope->blockStorage[$handler->mergeBlock] ?? null;
        $finallyBb = $context->scope->blockStorage[$finallyCfg] ?? null;
        // Empty `finally {}` often shares the merge CFG block. Reusing try_merge as
        // finallyBb appends set_return_pending + br after the merge header's
        // `br try_merge_body` → "Terminator found in the middle of a basic block"
        // (#35535 / re-#32371). Also reject mergeBody collision (#24105).
        if (
            null !== $finallyBb
            && (
                $finallyBb === $handler->mergeBodyLlvmBb
                || $finallyBb === $mergeHeaderBb
            )
        ) {
            $finallyBb = null;
        }
        if (null === $finallyBb) {
            $finallyBb = self::appendBlock($func, 'try_finally_body_'.self::blockSuffix($handler));
            // Do not overwrite blockStorage[mergeBlock] when finally CFG === merge.
            if ($finallyCfg !== $handler->mergeBlock) {
                $context->scope->blockStorage[$finallyCfg] = $finallyBb;
            }
        }
        $handler->finallyBb = $finallyBb;
        if ($compileBody && !$handler->finallyBodyCompiled) {
            self::compileFinallyBody($jit, $func, $context, $handler, $args);
        }

        return $finallyBb;
    }

    /**
     * @param list<Variable> $args
     */
    private static function compileFinallyBody(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): void {
        if ($handler->finallyBodyCompiled || null === $handler->finallyBb || null === $handler->finallyOp?->block1) {
            return;
        }
        $handler->finallyBodyCompiled = true;
        $builder = $context->builder;
        $finallyCfg = $handler->finallyOp->block1;
        // Empty `finally {}` shares the try merge CFG block. Compiling that block as the
        // finally body re-lowers the post-try fallthrough (typed missing-return TypeError
        // path) under an active finally — deferReturn branches back to finallyBb forever
        // (#35535). Fall through to the epilogue only.
        if ($finallyCfg === $handler->mergeBlock) {
            $builder->positionAtEnd($handler->finallyBb);
            if (null === $handler->finallyBb->getTerminator()) {
                $builder->branch(self::finallyEpilogueBbFor($jit, $func, $context, $handler, $args));
            }

            return;
        }
        $compiledTail = $jit->compileFinallyAtEntry($func, $finallyCfg, $handler->finallyBb, ...$args);
        // Prefer the live tail — boxed stores / extra BBs terminate finallyBb itself
        // (`$x+=`, echo helpers). Repositioning at the start then skipped the epilogue
        // branch whenever that entry already had a terminator; sealFunction emitted
        // `ret void` on the open tail, so post-try/catch/finally code never ran (#32371).
        // Same open-insert preference as catch-arm tails (#23641 / #24105).
        $openTail = $builder->getInsertBlock();
        if (null !== $openTail && null === $openTail->getTerminator()) {
            $compiledTail = $openTail;
        } elseif (null === $compiledTail || null !== $compiledTail->getTerminator()) {
            $compiledTail = $handler->finallyBb;
        }
        if (null !== $compiledTail && null === $compiledTail->getTerminator()) {
            $builder->positionAtEnd($compiledTail);
            $builder->branch(self::finallyEpilogueBbFor($jit, $func, $context, $handler, $args));
        }
    }

    /**
     * @param list<Variable> $args
     */
    private static function ensureFinallyLowering(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        return self::finallyBbFor($jit, $func, $context, $handler, $args, true);
    }

    /**
     * @param list<Variable> $args
     */
    private static function finallyEpilogueBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->finallyEpilogueBb) {
            return $handler->finallyEpilogueBb;
        }
        $mergeBb = $context->scope->blockStorage[$handler->mergeBlock] ?? null;
        $fallthroughBb = self::finallyNormalFallthroughBb($context, $handler) ?? $mergeBb;
        // Do not call dispatchBbFor here — it is unused and forced catch lowering before
        // beginTry finished wiring the handler (#24105).
        $epilogue = self::appendBlock($func, 'try_finally_epilogue_'.self::blockSuffix($handler));
        $handler->finallyEpilogueBb = $epilogue;
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($epilogue);
        $i32 = $context->getTypeFromString('int32');
        $hasReturn = $builder->call($context->lookupFunction('phpc_jit_has_return_pending'));
        $returnResume = self::returnResumeBbFor($jit, $func, $context, $handler);
        $hasReturnBool = $builder->icmp(Builder::INT_NE, $hasReturn, $i32->constInt(0, false));
        $afterReturnCheck = self::appendBlock($func, 'try_finally_after_return_'.self::blockSuffix($handler));
        $builder->branchIf($hasReturnBool, $returnResume, $afterReturnCheck);
        $builder->positionAtEnd($afterReturnCheck);
        $hasThrow = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
        $hasThrowBool = $builder->icmp(Builder::INT_NE, $hasThrow, $i32->constInt(0, false));
        $propagate = self::appendBlock($func, 'try_finally_propagate_'.self::blockSuffix($handler));
        $uncaught = self::appendBlock($func, 'try_finally_uncaught_'.self::blockSuffix($handler));
        // Leave (break/continue) after finally — before merge fallthrough (#35547 / #25240).
        $afterThrowCheck = self::appendBlock($func, 'try_finally_after_throw_'.self::blockSuffix($handler));
        $builder->branchIf($hasThrowBool, $propagate, $afterThrowCheck);
        $builder->positionAtEnd($afterThrowCheck);
        $leaveSlot = self::ensureLeavePendingSlot($context, $func, $handler);
        $leaveId = $builder->load($leaveSlot);
        $hasLeave = $builder->icmp(Builder::INT_NE, $leaveId, $i32->constInt(0, false));
        $leaveDispatch = $handler->leaveDispatchBb;
        if (null === $leaveDispatch) {
            $leaveDispatch = self::appendBlock($func, 'try_leave_dispatch_'.self::blockSuffix($handler));
            $handler->leaveDispatchBb = $leaveDispatch;
            $builder->positionAtEnd($leaveDispatch);
            if (null !== $fallthroughBb) {
                $builder->branch($fallthroughBb);
            } else {
                $builder->returnVoid();
            }
            $builder->positionAtEnd($afterThrowCheck);
        }
        if (null !== $fallthroughBb) {
            $builder->branchIf($hasLeave, $leaveDispatch, $fallthroughBb);
        } else {
            $builder->branchIf($hasLeave, $leaveDispatch, $uncaught);
        }
        $builder->positionAtEnd($propagate);
        // Peek at the enclosing handler without popping — popHandler here runs at
        // compile time and would drop the active try before the try body is lowered,
        // so throws never branch to dispatch (#24105).
        $stack = $context->tryCatch->handlerStack;
        $n = \count($stack);
        $outer = $n >= 2 ? $stack[$n - 2] : null;
        if (null !== $outer && null !== $outer->dispatchBb) {
            $builder->branch($outer->dispatchBb);
        } else {
            $builder->branch($uncaught);
        }
        $builder->positionAtEnd($uncaught);
        // Pending throw survived finally with no same-function catch. Non-main callees
        // must return with throw-pending set so the caller's emitCheckPendingThrowAfterCall
        // can land in an outer catch — raw abort() skipped that path (#28623 / re-#24105).
        // Main / uncaught: Zend-shaped fatal via emitUncaughtUserHandlerOrAbort (#24680).
        $pendingObj = $builder->call($context->lookupFunction('phpc_jit_take_throw_pending'));
        $cfgBlock = $handler->finallyOp->block1 ?? $handler->mergeBlock;
        if (self::isNonMainUserFunction($cfgBlock)) {
            $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
            self::emitPropagateReturn($context, $func);
        } else {
            self::emitUncaughtUserHandlerOrAbort($context, $pendingObj);
        }
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $epilogue;
    }

    private static function returnResumeBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler
    ): BasicBlock {
        if (null !== $handler->returnResumeBb) {
            return $handler->returnResumeBb;
        }
        $resume = self::appendBlock($func, 'try_return_resume_'.self::blockSuffix($handler));
        $handler->returnResumeBb = $resume;
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($resume);
        $jit->emitPendingReturnResume($func);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $resume;
    }

    private static function blockSuffix(TryCatchHandler $handler): string
    {
        return (string) spl_object_id($handler);
    }

    private static function appendBlock(Function_ $func, string $name): BasicBlock
    {
        return $func->appendBasicBlock($name);
    }

    /** NestedJitCompileScope clears LLVM insertion position — getInsertBlock() throws (#8559). */
    private static function probeInsertBlock(Builder $builder): ?BasicBlock
    {
        try {
            return $builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Non-main user function: throw propagates via throw-pending + return (#24680).
     */
    private static function isNonMainUserFunction(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }

        return '{main}' !== $block->func->name;
    }

    /**
     * Return from the current LLVM function after setting throw-pending (#24680).
     *
     * The return value is irrelevant — the caller checks throw-pending before using it.
     * Must still match the LLVM signature width: `constantFromInteger(0)` is always i64,
     * which fails module verify for `: bool` (i1) / `: float` (double) functions whose
     * untyped-arg paths emit many TypeError propagate returns (#34524).
     */
    private static function emitPropagateReturn(Context $context, Function_ $func): void
    {
        $builder = $context->builder;
        if (BasicBlockHelper::isVoidLlvmFunctionValue($func)) {
            $builder->returnVoid();

            return;
        }
        $sig = BasicBlockHelper::llvmFunctionSignatureType($func);
        if (null === $sig) {
            $builder->returnValue($context->constantFromInteger(0));

            return;
        }
        $retType = $sig->getReturnType();
        $kind = $retType->getKind();
        if (\PHPLLVM\Type::KIND_STRUCT === $kind) {
            $slot = JitValueBox::alloc($context);
            $builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );
            $builder->returnValue($builder->load($slot));
        } elseif (\PHPLLVM\Type::KIND_POINTER === $kind) {
            $builder->returnValue($retType->constNull());
        } elseif (\PHPLLVM\Type::KIND_INTEGER === $kind) {
            // i1 (`: bool`), i32, i64 — same shape as InstantiableClassJitGuard (#34524).
            $builder->returnValue($retType->constInt(0, false));
        } elseif (
            \PHPLLVM\Type::KIND_FLOAT === $kind
            || \PHPLLVM\Type::KIND_DOUBLE === $kind
        ) {
            $builder->returnValue($retType->constReal(0.0));
        } else {
            $builder->returnValue($context->constantFromInteger(0));
        }
    }
}

final class TryCatchHandler
{
    /** Remaining TYPE_CATCH/TYPE_FINALLY CFG opcodes to skip after beginTry (#2114). */
    public int $postTryOpcodesRemaining = 0;

    public bool $mergeEntryEmitted = false;

    public bool $mergeBodyCompiled = false;

    public bool $finallyBodyCompiled = false;

    public ?OpCode $finallyOp = null;

    public ?BasicBlock $mergeEntryBb = null;

    public ?BasicBlock $dispatchBb = null;

    public ?BasicBlock $finallyBb = null;

    public ?BasicBlock $mergeBodyLlvmBb = null;

    public ?BasicBlock $finallyEpilogueBb = null;

    public ?BasicBlock $returnResumeBb = null;

    /** Try body CFG entry (TYPE_TRY block1) for leave-vs-intra-try detection (#35547). */
    public ?Block $tryBodyBlock = null;

    /** i32 alloca: 0 = no pending leave; else id in {@see $leaveTargets}. */
    public ?Value $leavePendingSlot = null;

    public ?BasicBlock $leaveDispatchBb = null;

    public int $nextLeaveId = 1;

    /** True after {@see TryCatchHelper::rebuildLeaveDispatch} runs once for this handler. */
    public bool $leaveDispatchBuilt = false;

    /**
     * Pending non-merge leave resumes (break/goto) after finally (#35547).
     *
     * @var array<int, array{cfg: Block, llvm: BasicBlock}>
     */
    public array $leaveTargets = [];

    /** @var array<int, int> CFG object id => leave id */
    public array $leaveTargetIds = [];

    /**
     * @param list<array{op: OpCode, catchTypes: list<string>}> $catchArms
     */
    public function __construct(
        public Block $mergeBlock,
        public array $catchArms,
    ) {
    }
}
