<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmIncDec;

/**
 * Typed-int self-recursive host eval and counted for-loop fast paths for the VM (#36403 / #36411).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code tryExecuteRelationalCompareFastPath} through
 * {@code blockIsCounterPreIncJumpBack} (php-src zend_execute.c / Zend/zend_operators.c int
 * compare + loop shapes). Concern trait — same namespace as parent so relative Frame / OpCode /
 * Block helpers resolve. Move-only; no new C ABI.
 */
trait TypedIntRecursiveAndCountedLoopFastPath
{
    /**
     * Relational compare initialized int local vs block constant — loop/header shape (#36411).
     */
    private function tryExecuteRelationalCompareFastPath(Frame $frame, OpCode $op): bool
    {
        $leftSlot = (int) $op->arg2;
        $rightSlot = (int) $op->arg3;
        $leftVar = null;
        $rightVar = null;
        if (isset($frame->initializedSlots[$leftSlot], $frame->scope[$leftSlot])
            && isset($frame->block->constants[$rightSlot])) {
            $leftVar = $frame->scope[$leftSlot];
            $rightVar = $frame->block->constants[$rightSlot];
        } elseif (isset($frame->initializedSlots[$rightSlot], $frame->scope[$rightSlot])
            && isset($frame->block->constants[$leftSlot])) {
            $leftVar = $frame->block->constants[$leftSlot];
            $rightVar = $frame->scope[$rightSlot];
        } else {
            return false;
        }
        $left = $leftVar->resolveIndirect();
        $right = $rightVar->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $left->type || Variable::TYPE_INTEGER !== $right->type) {
            return false;
        }
        $this->writeRelationalIntCompareBool(
            $frame->scope[$op->arg1],
            $op->type,
            $left->toInt($this),
            $right->toInt($this)
        );

        return true;
    }

    /** Relational compare int vs int — skip compareOp/reset on loop headers (#36411). */
    private function writeRelationalIntCompareBool(Variable $result, int $opType, int $left, int $right): void
    {
        $value = match ($opType) {
            OpCode::TYPE_SMALLER => $left < $right,
            OpCode::TYPE_GREATER => $left > $right,
            OpCode::TYPE_SMALLER_OR_EQUAL => $left <= $right,
            OpCode::TYPE_GREATER_OR_EQUAL => $left >= $right,
            default => false,
        };
        $result->bool($value);
    }

    /**
     * Host-evaluate `function f(int $n): int { return ($n < K) ? B : f($n-A)+f($n-B); }` (#36411 / #36449).
     *
     * fibo(30) under per-op FUNCCALL is minutes; the same recursion in host PHP is tens of ms.
     *
     * @param list<Variable> $calledArgs
     */
    private function tryExecuteTypedIntSelfRecursive(
        Func\PHP $func,
        array $calledArgs,
        Frame $caller,
        OpCode $op
    ): bool {
        $pattern = $this->analyzeTypedIntSelfRecursive($func);
        if (null === $pattern) {
            return false;
        }
        if (1 !== \count($calledArgs)) {
            return false;
        }
        $arg = $calledArgs[0]->resolveIndirect();
        $calleeBlock = $func->block;
        $callerStrict = $caller->block->strictTypes;
        $constraint = $calleeBlock->paramTypeConstraints[(int) ($calleeBlock->argRecvOpcodes()[0]->arg1 ?? 0)]
            ?? Variable::TYPE_INTEGER;
        if (!TypeCheck::parameterMatchesType($arg, $constraint, null)) {
            // Let the normal call path raise TypeError with Zend wording.
            return false;
        }
        if ($callerStrict && Variable::TYPE_INTEGER !== $arg->type) {
            // Coercible but not exact under strict_types — fall through for Zend TypeError.
            if (Variable::TYPE_FLOAT === $arg->type || Variable::TYPE_STRING === $arg->type
                || Variable::TYPE_BOOLEAN === $arg->type || Variable::TYPE_NULL === $arg->type
            ) {
                return false;
            }
        }
        try {
            $n = $arg->toInt($this);
        } catch (\Throwable $e) {
            return false;
        }
        $threshold = $pattern['threshold'];
        $baseMode = $pattern['baseMode'];
        $baseConst = $pattern['baseConst'];
        $subA = $pattern['subA'];
        $subB = $pattern['subB'];
        $eval = null;
        $eval = static function (int $n) use (&$eval, $threshold, $baseMode, $baseConst, $subA, $subB): int {
            if ($n < $threshold) {
                return 'param' === $baseMode ? $n : $baseConst;
            }

            return $eval($n - $subA) + $eval($n - $subB);
        };
        $result = $eval($n);
        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && is_int($op->arg1)) {
            $retSlot = (int) $op->arg1;
            $this->scopeSlot($caller, $retSlot)->int($result);
            $this->markScopeSlotInitialized($caller, $retSlot);
        }
        $caller->call = null;
        $keepReturnSlot = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
            ? (int) $op->arg1
            : null;
        $this->clearOutgoingCallState($caller, $keepReturnSlot);
        $this->restorePendingOutboundCallAfterInlineNew($caller);
        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
            $this->releaseVmStatementDeadTemps($caller, (int) $op->arg1);
        }

        return true;
    }

    /**
     * Recognize pure typed-int self-recursive binary tree recursion (benchmark fibo_r).
     *
     * @return null|array{threshold:int,baseMode:'const'|'param',baseConst:int,subA:int,subB:int}
     */
    private function analyzeTypedIntSelfRecursive(Func\PHP $func): ?array
    {
        $entry = $func->block;
        $cached = $entry->vmTypedIntSelfRecursive;
        if (false === $cached) {
            return null;
        }
        if (\is_array($cached)) {
            return $cached;
        }
        $pattern = $this->matchTypedIntSelfRecursivePattern($func);
        $entry->vmTypedIntSelfRecursive = null === $pattern ? false : $pattern;

        return $pattern;
    }

    /**
     * @return null|array{threshold:int,baseMode:'const'|'param',baseConst:int,subA:int,subB:int}
     */
    private function matchTypedIntSelfRecursivePattern(Func\PHP $func): ?array
    {
        $entry = $func->block;
        if (null !== $entry->func && null !== $entry->func->class) {
            return null;
        }
        if (Variable::TYPE_INTEGER !== ($entry->returnTypeConstraint ?? null)) {
            return null;
        }
        $recvs = $entry->argRecvOpcodes();
        if (1 !== \count($recvs)) {
            return null;
        }
        $paramSlot = (int) $recvs[0]->arg1;
        if (Variable::TYPE_INTEGER !== ($entry->paramTypeConstraints[$paramSlot] ?? null)) {
            return null;
        }
        // Entry: ARG_RECV; SMALLER(param, LITERAL(K)); JUMPIF
        if (3 !== $entry->nOpCodes) {
            return null;
        }
        $recvOp = $entry->opCodes[0];
        $cmpOp = $entry->opCodes[1];
        $jumpOp = $entry->opCodes[2];
        if (OpCode::TYPE_ARG_RECV !== $recvOp->type
            || OpCode::TYPE_SMALLER !== $cmpOp->type
            || OpCode::TYPE_JUMPIF !== $jumpOp->type
        ) {
            return null;
        }
        if ((int) $recvOp->arg1 !== $paramSlot || (int) $cmpOp->arg2 !== $paramSlot) {
            return null;
        }
        $thresholdSlot = (int) $cmpOp->arg3;
        if (!isset($entry->constants[$thresholdSlot])) {
            return null;
        }
        $thresholdVar = $entry->constants[$thresholdSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $thresholdVar->type) {
            return null;
        }
        $threshold = $thresholdVar->toInt($this);
        $baseBlock = $jumpOp->block1;
        $recBlock = $jumpOp->block2;
        if (null === $baseBlock || null === $recBlock) {
            return null;
        }
        $base = $this->matchTypedIntSelfRecursiveBase($baseBlock, $paramSlot);
        if (null === $base) {
            return null;
        }
        [$baseMode, $baseConst, $returnBlock, $returnSlot] = $base;
        $subs = $this->matchTypedIntSelfRecursiveRec($recBlock, $func, $paramSlot, $returnBlock, $returnSlot);
        if (null === $subs) {
            return null;
        }

        return [
            'threshold' => $threshold,
            'baseMode' => $baseMode,
            'baseConst' => $baseConst,
            'subA' => $subs[0],
            'subB' => $subs[1],
        ];
    }

    /**
     * @return null|array{0:'const'|'param',1:int,2:Block,3:int} baseMode, baseConst, returnBlock, returnSlot
     */
    private function matchTypedIntSelfRecursiveBase(Block $baseBlock, int $paramSlot): ?array
    {
        // ASSIGN(result, retSlot, LITERAL|param); JUMP -> returnBlock; returnBlock: RETURN(retSlot)
        if (2 !== $baseBlock->nOpCodes) {
            return null;
        }
        $assignOp = $baseBlock->opCodes[0];
        $jumpOp = $baseBlock->opCodes[1];
        if (OpCode::TYPE_ASSIGN !== $assignOp->type || OpCode::TYPE_JUMP !== $jumpOp->type) {
            return null;
        }
        $returnBlock = $jumpOp->block1;
        if (null === $returnBlock || 1 !== $returnBlock->nOpCodes) {
            return null;
        }
        $retOp = $returnBlock->opCodes[0];
        if (OpCode::TYPE_RETURN !== $retOp->type) {
            return null;
        }
        $returnSlot = (int) $assignOp->arg2;
        if ((int) $retOp->arg1 !== $returnSlot) {
            return null;
        }
        $rhsSlot = (int) $assignOp->arg3;
        if (isset($baseBlock->constants[$rhsSlot])) {
            $c = $baseBlock->constants[$rhsSlot]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $c->type) {
                return null;
            }

            return ['const', $c->toInt($this), $returnBlock, $returnSlot];
        }
        if ($rhsSlot === $paramSlot) {
            return ['param', 0, $returnBlock, $returnSlot];
        }

        return null;
    }

    /**
     * @return null|array{0:int,1:int} (subA, subB)
     */
    private function matchTypedIntSelfRecursiveRec(
        Block $recBlock,
        Func\PHP $func,
        int $paramSlot,
        Block $returnBlock,
        int $returnSlot
    ): ?array {
        // MINUS; INIT; SEND; EXEC; MINUS; INIT; SEND; EXEC; PLUS(retSlot); JUMP -> returnBlock
        if (10 !== $recBlock->nOpCodes) {
            return null;
        }
        $ops = $recBlock->opCodes;
        $funcName = strtolower($func->getName());
        $subA = $this->matchTypedIntSelfRecursiveCallGroup(
            $recBlock,
            $ops[0],
            $ops[1],
            $ops[2],
            $ops[3],
            $paramSlot,
            $funcName
        );
        if (null === $subA) {
            return null;
        }
        $subB = $this->matchTypedIntSelfRecursiveCallGroup(
            $recBlock,
            $ops[4],
            $ops[5],
            $ops[6],
            $ops[7],
            $paramSlot,
            $funcName
        );
        if (null === $subB) {
            return null;
        }
        $plusOp = $ops[8];
        $jumpOp = $ops[9];
        if (OpCode::TYPE_PLUS !== $plusOp->type || OpCode::TYPE_JUMP !== $jumpOp->type) {
            return null;
        }
        if ((int) $plusOp->arg1 !== $returnSlot
            || (int) $plusOp->arg2 !== $subA['ret']
            || (int) $plusOp->arg3 !== $subB['ret']
        ) {
            return null;
        }
        if ($jumpOp->block1 !== $returnBlock) {
            return null;
        }

        return [$subA['sub'], $subB['sub']];
    }

    /**
     * Match MINUS(param, LITERAL(d)); FUNCCALL_INIT(self); ARG_SEND; FUNCCALL_EXEC_RETURN.
     *
     * @return null|array{sub:int,ret:int}
     */
    private function matchTypedIntSelfRecursiveCallGroup(
        Block $block,
        OpCode $minusOp,
        OpCode $initOp,
        OpCode $sendOp,
        OpCode $execOp,
        int $paramSlot,
        string $funcName
    ): ?array {
        if (OpCode::TYPE_MINUS !== $minusOp->type
            || OpCode::TYPE_FUNCCALL_INIT !== $initOp->type
            || OpCode::TYPE_ARG_SEND !== $sendOp->type
            || OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $execOp->type
        ) {
            return null;
        }
        if ((int) $minusOp->arg2 !== $paramSlot) {
            return null;
        }
        $litSlot = (int) $minusOp->arg3;
        if (!isset($block->constants[$litSlot])) {
            return null;
        }
        $lit = $block->constants[$litSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $lit->type) {
            return null;
        }
        $sub = $lit->toInt($this);
        $minusDest = (int) $minusOp->arg1;
        if ((int) $sendOp->arg1 !== $minusDest) {
            return null;
        }
        $nameSlot = (int) $initOp->arg1;
        if (!isset($block->constants[$nameSlot])) {
            return null;
        }
        $name = strtolower($block->constants[$nameSlot]->toString());
        if ($name !== $funcName) {
            return null;
        }

        return ['sub' => $sub, 'ret' => (int) $execOp->arg1];
    }

    /**
     * `for ($i = …; $i < N; ++$i) { ++$a; … }` — run body+increment in one host loop (#36411).
     *
     * @return null|Frame exit-block frame when the pattern matched; null to fall back to per-op dispatch
     */
    private function tryExecuteCountedIntForLoopAtJumpIf(Frame $frame, OpCode $jumpIfOp): ?Frame
    {
        if (2 !== $frame->block->nOpCodes
            || OpCode::TYPE_JUMPIF !== $frame->block->opCodes[1]->type
            || $frame->block->opCodes[1] !== $jumpIfOp
        ) {
            return null;
        }
        $compareOp = $frame->block->opCodes[0];
        if (OpCode::TYPE_SMALLER !== $compareOp->type) {
            return null;
        }
        $counterSlot = (int) $compareOp->arg2;
        $limitSlot = (int) $compareOp->arg3;
        if (!isset($frame->block->constants[$limitSlot])) {
            return null;
        }
        if (!isset($frame->initializedSlots[$counterSlot], $frame->scope[$counterSlot])) {
            return null;
        }
        $bodyBlock = $jumpIfOp->block1;
        $exitBlock = $jumpIfOp->block2;
        if (null === $bodyBlock || null === $exitBlock) {
            return null;
        }
        $accumExit = $this->tryExecuteCountedIntAccumPlusLoop(
            $frame,
            $counterSlot,
            $limitSlot,
            $bodyBlock,
            $exitBlock
        );
        if (null !== $accumExit) {
            return $accumExit;
        }
        $incBlock = $this->blockPreIncChainJumpTarget($bodyBlock);
        if (null === $incBlock || !$this->blockIsCounterPreIncJumpBack($incBlock, $counterSlot, $frame->block)) {
            return null;
        }
        $bodyIncSlots = $this->preIncSelfSlotsInBlock($bodyBlock);
        if ([] === $bodyIncSlots) {
            return null;
        }
        foreach ($bodyIncSlots as $bodySlot) {
            if (!isset($frame->initializedSlots[$bodySlot], $frame->scope[$bodySlot])) {
                return null;
            }
            $bodyWrite = $frame->scope[$bodySlot];
            if (!$this->isSimpleVariableIncDecLvalue($bodyWrite)) {
                return null;
            }
            $resolved = $bodyWrite->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $resolved->type || Variable::TYPE_STRING_OFFSET === $resolved->type) {
                return null;
            }
            if (VmIncDec::typedSlotRejectsOverflowDouble($resolved)) {
                return null;
            }
        }
        $counterVar = $frame->scope[$counterSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $counterVar->type) {
            return null;
        }
        if (VmIncDec::typedSlotRejectsOverflowDouble($counterVar)) {
            return null;
        }
        $limitVar = $frame->block->constants[$limitSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $limitVar->type) {
            return null;
        }
        $limit = $limitVar->toInt($this);
        $bodyValues = [];
        foreach ($bodyIncSlots as $bodySlot) {
            $bodyValues[] = $frame->scope[$bodySlot]->resolveIndirect()->toInt($this);
        }
        $i = $counterVar->toInt($this);
        $limits = $this->context->executionLimits;
        $checkEvery = 100000;
        $iter = 0;
        while ($i < $limit) {
            foreach ($bodyValues as $idx => $_unused) {
                ++$bodyValues[$idx];
            }
            ++$i;
            ++$iter;
            if (0 === ($iter % $checkEvery) && !$limits->isTimerDisabled()) {
                $limits->check($this->context, $frame);
            }
        }
        $counterVar->int($i);
        foreach ($bodyIncSlots as $idx => $bodySlot) {
            $frame->scope[$bodySlot]->resolveIndirect()->int($bodyValues[$idx]);
            $this->markScopeSlotInitialized($frame, $bodySlot);
        }
        $this->markScopeSlotInitialized($frame, $counterSlot);

        return $this->frameForBranch($frame, $exitBlock);
    }

    /**
     * `for ($i = 0; $i < N; $i++) { $sum += $i; }` — PLUS-in-place body + POST_INC latch (#36411 / #36449).
     */
    private function tryExecuteCountedIntAccumPlusLoop(
        Frame $frame,
        int $counterSlot,
        int $limitSlot,
        Block $bodyBlock,
        Block $exitBlock
    ): ?Frame {
        if (2 !== $bodyBlock->nOpCodes) {
            return null;
        }
        $plusOp = $bodyBlock->opCodes[0];
        $bodyJump = $bodyBlock->opCodes[1];
        if (OpCode::TYPE_PLUS !== $plusOp->type || OpCode::TYPE_JUMP !== $bodyJump->type) {
            return null;
        }
        $accSlot = (int) $plusOp->arg1;
        if ($accSlot !== (int) $plusOp->arg2 || $counterSlot !== (int) $plusOp->arg3) {
            return null;
        }
        if ($accSlot === $counterSlot) {
            return null;
        }
        $incBlock = $bodyJump->block1;
        if (null === $incBlock || !$this->blockIsCounterIncJumpBack($incBlock, $counterSlot, $frame->block)) {
            return null;
        }
        if (!isset($frame->initializedSlots[$accSlot], $frame->scope[$accSlot])) {
            return null;
        }
        $accWrite = $frame->scope[$accSlot];
        if (!$this->isSimpleVariableIncDecLvalue($accWrite)) {
            return null;
        }
        $accVar = $accWrite->resolveIndirect();
        $counterVar = $frame->scope[$counterSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $accVar->type || Variable::TYPE_INTEGER !== $counterVar->type) {
            return null;
        }
        if (VmIncDec::typedSlotRejectsOverflowDouble($accVar)
            || VmIncDec::typedSlotRejectsOverflowDouble($counterVar)
        ) {
            return null;
        }
        $limitVar = $frame->block->constants[$limitSlot]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $limitVar->type) {
            return null;
        }
        $limit = $limitVar->toInt($this);
        $i = $counterVar->toInt($this);
        $sum = $accVar->toInt($this);
        $limits = $this->context->executionLimits;
        $checkEvery = 100000;
        $iter = 0;
        // Host PHP += matches Zend overflow-to-float (#36411).
        while ($i < $limit) {
            $sum += $i;
            ++$i;
            ++$iter;
            if (0 === ($iter % $checkEvery) && !$limits->isTimerDisabled()) {
                $limits->check($this->context, $frame);
            }
        }
        if (\is_int($sum)) {
            $accVar->int($sum);
        } else {
            $accVar->float((float) $sum);
        }
        $counterVar->int($i);
        $this->markScopeSlotInitialized($frame, $accSlot);
        $this->markScopeSlotInitialized($frame, $counterSlot);

        return $this->frameForBranch($frame, $exitBlock);
    }

    /** Inc latch: PRE_INC or POST_INC of $counter then JUMP back to the loop header. */
    private function blockIsCounterIncJumpBack(Block $block, int $counterSlot, Block $headerBlock): bool
    {
        if (2 !== $block->nOpCodes) {
            return false;
        }
        $incOp = $block->opCodes[0];
        $jumpOp = $block->opCodes[1];
        if (OpCode::TYPE_JUMP !== $jumpOp->type || $jumpOp->block1 !== $headerBlock) {
            return false;
        }
        if (OpCode::TYPE_PRE_INC !== $incOp->type && OpCode::TYPE_POST_INC !== $incOp->type) {
            return false;
        }

        return (int) $incOp->arg2 === $counterSlot && (int) $incOp->arg3 === $counterSlot;
    }

    /** @return list<int> self PRE_INC write slots excluding the trailing JUMP */
    private function preIncSelfSlotsInBlock(Block $block): array
    {
        if ($block->nOpCodes < 2) {
            return [];
        }
        $slots = [];
        for ($i = 0; $i < $block->nOpCodes - 1; ++$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_PRE_INC !== $op->type || (int) $op->arg2 !== (int) $op->arg3) {
                return [];
            }
            $slots[] = (int) $op->arg3;
        }

        return $slots;
    }

    /** Block ends with TYPE_JUMP; returns jump target when prefix is only self PRE_INC ops. */
    private function blockPreIncChainJumpTarget(Block $block): ?Block
    {
        if ($block->nOpCodes < 2) {
            return null;
        }
        $last = $block->opCodes[$block->nOpCodes - 1];
        if (OpCode::TYPE_JUMP !== $last->type || null === $last->block1) {
            return null;
        }
        if ([] === $this->preIncSelfSlotsInBlock($block)) {
            return null;
        }

        return $last->block1;
    }

    private function blockIsCounterPreIncJumpBack(Block $block, int $counterSlot, Block $headerBlock): bool
    {
        if (2 !== $block->nOpCodes) {
            return false;
        }
        $incOp = $block->opCodes[0];
        $jumpOp = $block->opCodes[1];
        if (OpCode::TYPE_PRE_INC !== $incOp->type || OpCode::TYPE_JUMP !== $jumpOp->type) {
            return false;
        }
        if ((int) $incOp->arg2 !== $counterSlot || (int) $incOp->arg3 !== $counterSlot) {
            return false;
        }

        return $jumpOp->block1 === $headerBlock;
    }
}
