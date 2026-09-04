<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * foreach by-ref fusion + list()/[] destructuring compile helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; list
 * destruct slot wiring relies on coercion (same as other Concern extracts).
 */
trait ListDestructAndForeach
{
    /**
     * foreach ($iterable as &$loopVar) — fuse Iterator\\Value + AssignRef into one ITER_VALUE (#4431).
     *
     * @param Op[] $ops
     */
    private function isForeachLoopVarAssignRefFusion(array $ops, int $index): bool
    {
        if (!isset($ops[$index + 1])) {
            return false;
        }
        if (!$ops[$index] instanceof Op\Iterator\Value) {
            return false;
        }
        if (!$ops[$index + 1] instanceof Op\Expr\AssignRef) {
            return false;
        }
        /** @var Op\Iterator\Value $iter */
        $iter = $ops[$index];
        /** @var Op\Expr\AssignRef $assign */
        $assign = $ops[$index + 1];

        return $iter->byRef
            && $iter->result === $assign->expr
            && !$this->operandIsPropertyWriteTarget($assign->var);
    }

    /**
     * foreach ($iterable as &$loopVar) fusion — CV slot must feed inline call-arg producers (#25302).
     */
    private function registerForeachByRefLoopVarBindings(
        Block $block,
        Op\Expr\AssignRef $assign,
        Op\Iterator\Value $iter,
        int $destSlot
    ): void {
        $varRoot = Block::cfgVarRoot($assign->var);
        if (null !== $varRoot) {
            $block->registerNamedAssignDest($varRoot, $destSlot);
        }
        if ($iter->result !== $assign->var && [] !== $iter->result->usages) {
            $block->registerAssignResultLvalue(
                $this->compileOperand($iter->result, $block, false),
                $destSlot
            );
        }
        if ($assign->result !== $assign->var && [] !== $assign->result->usages) {
            $block->registerAssignResultLvalue(
                $this->compileOperand($assign->result, $block, false),
                $destSlot
            );
        }
    }

    /**
     * foreach ($iterable as list(&$v)) / [$x, &$y] — by-ref slots need live iteration value (#16213).
     *
     * @param Op[] $ops
     */
    private function isForeachListDestructRefFusion(array $ops, int $index, ?Block $block = null): bool
    {
        if ($this->isForeachLoopVarAssignRefFusion($ops, $index)) {
            return false;
        }
        if (!$ops[$index] instanceof Op\Iterator\Value) {
            return false;
        }
        $next = $index + 1;
        if ($next >= count($ops) || !$this->isListDestructGroupStart($ops, $next, $block)) {
            return false;
        }

        return $this->listDestructGroupHasAssignRef($ops, $next, $block);
    }

    /**
     * @param Op[] $ops
     */
    private function listDestructGroupHasAssignRef(array $ops, int $start, ?Block $block = null): bool
    {
        $end = $this->listDestructGroupEndIndex($ops, $start, $block);
        for ($i = $start; $i <= $end; ++$i) {
            if ($ops[$i] instanceof Op\Expr\AssignRef) {
                return true;
            }
        }

        return false;
    }

    /**
     * foreach ($iterable as &$obj->hookedProp) — Iterator\\Value [, PropertyFetch] AssignRef (#6435).
     *
     * @param Op[] $ops
     */
    private function isForeachPropertyHookAssignRefPair(array $ops, int $assignIndex): bool
    {
        if (!isset($ops[$assignIndex]) || !$ops[$assignIndex] instanceof Op\Expr\AssignRef) {
            return false;
        }
        /** @var Op\Expr\AssignRef $assign */
        $assign = $ops[$assignIndex];
        $cursor = $assignIndex - 1;
        if ($cursor >= 0 && $this->isListDestructPropertyFetchStmt($ops[$cursor])) {
            --$cursor;
        }
        if ($cursor < 0 || !$ops[$cursor] instanceof Op\Iterator\Value) {
            return false;
        }
        /** @var Op\Iterator\Value $iter */
        $iter = $ops[$cursor];

        return $iter->byRef
            && $iter->result === $assign->expr
            && (
                $this->operandIsPropertyWriteTarget($assign->var)
                || ($assignIndex > 0 && $this->isListDestructPropertyFetchStmt($ops[$assignIndex - 1]))
            );
    }

    private function operandIsPropertyWriteTarget(Operand $operand): bool
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\PropertyFetch
            || $operand instanceof Op\Expr\StaticPropertyFetch;
    }

    /**
     * php-cfg may emit PropertyFetch before Assign for hooked list slots (#6434).
     */
    private function isListDestructPropertyFetchStmt(Op $op): bool
    {
        return $op instanceof Op\Expr\PropertyFetch || $op instanceof Op\Expr\StaticPropertyFetch;
    }

    /**
     * Assign/AssignRef index for one list slot after write-target prelude ops (#6434, #7286).
     *
     * @param Op[] $ops
     */
    private function listDestructSlotAssignIndex(array $ops, int $index): ?int
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return null;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        for ($cursor = $index + 1, $count = count($ops); $cursor < $count; ++$cursor) {
            $op = $ops[$cursor];
            if ($op instanceof Op\Expr\Assign || $op instanceof Op\Expr\AssignRef) {
                return $op->expr === $fetch->result ? $cursor : null;
            }
            if (!$this->isListDestructWriteTargetPreludeOp($op)) {
                return null;
            }
        }

        return null;
    }

    /**
     * CFG ops between a list RHS dim fetch and its slot Assign when the write target is complex.
     */
    private function isListDestructWriteTargetPreludeOp(Op $op): bool
    {
        return $op instanceof Op\Expr\New_
            || $this->isListDestructPropertyFetchStmt($op)
            || $op instanceof Op\Expr\ArrayDimFetch;
    }

    /**
     * php-cfg lowers `["key" => $v] = $array` to array literal + dim fetch + assign pairs (#1234).
     *
     * @param Op[] $ops
     */
    private function isKeyedListDestructDimFetch(array $ops, int $index, ?Block $block = null): bool
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        if (null === $fetch->var) {
            return false;
        }
        if (!$fetch->dim instanceof Literal || !is_string($fetch->dim->value)) {
            return false;
        }
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null === $assignIndex) {
            return false;
        }
        /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
        $assign = $ops[$assignIndex];
        if ($assign->expr !== $fetch->result) {
            return false;
        }
        // Distinguish `["k" => $v] = $rhs` from `$v = $rhs["k"]` (#22646).
        if (!$this->isListAssignmentLoweredDimFetch($fetch, $assign)) {
            return false;
        }

        return $this->assignIsListDestructSlotTarget($assign->var, $block);
    }

    private function assignIsListSpread(Op\Expr\Assign $assign): bool
    {
        return property_exists($assign, 'listSpreadRhs')
            && null !== $assign->listSpreadRhs
            && property_exists($assign, 'listSpreadFromIndex')
            && null !== $assign->listSpreadFromIndex;
    }

    private function isListSpreadAssignOp(Op $op): bool
    {
        return $op instanceof Op\Expr\Assign && $this->assignIsListSpread($op);
    }

    /**
     * php-cfg lowers `list($a, …) = $rhs` to integer-key dim fetches (#4298).
     *
     * @param Op[] $ops
     */
    private function isListDestructGroupStart(array $ops, int $index, ?Block $block = null): bool
    {
        if ($this->isListSpreadAssignOp($ops[$index])) {
            return !$this->isListDestructSpreadTail($ops, $index, $block);
        }
        if (
            !$this->isPlainListDestructDimFetch($ops, $index, $block)
            && !$this->isKeyedListDestructDimFetch($ops, $index, $block)
        ) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $cur */
        $cur = $ops[$index];
        $p = $index - 1;
        while ($p >= 0) {
            $op = $ops[$p];
            if ($op instanceof Op\Expr\Assign || $op instanceof Op\Expr\AssignRef) {
                --$p;
                continue;
            }
            if (
                $op instanceof Op\Expr\ArrayDimFetch
                && ($this->isPlainListDestructDimFetch($ops, $p, $block) || $this->isKeyedListDestructDimFetch($ops, $p, $block))
                && $op->var === $cur->var
            ) {
                return false;
            }

            break;
        }

        return true;
    }

    /**
     * Spread arm at the end of `[$a, ...$rest] = $rhs` — not a separate group start (#4835).
     *
     * @param Op[] $ops
     */
    private function isListDestructSpreadTail(array $ops, int $index, ?Block $block = null): bool
    {
        if (!$this->isListSpreadAssignOp($ops[$index])) {
            return false;
        }
        if ($index < 1) {
            return false;
        }
        $p = $index - 1;
        if ($ops[$p] instanceof Op\Expr\Assign || $ops[$p] instanceof Op\Expr\AssignRef) {
            --$p;
        }

        return $p >= 0
            && ($this->isPlainListDestructDimFetch($ops, $p, $block) || $this->isKeyedListDestructDimFetch($ops, $p, $block));
    }

    /**
     * @param Op[] $ops
     */
    private function isPlainListDestructDimFetch(array $ops, int $index, ?Block $block = null): bool
    {
        if (!$ops[$index] instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if ($this->isKeyedListDestructDimFetch($ops, $index, $block)) {
            return false;
        }
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        if (null === $fetch->var) {
            return false;
        }
        if (!$fetch->dim instanceof Operand\Literal || !is_int($fetch->dim->value)) {
            return false;
        }

        return $this->isListDestructDimFetchConsumer($ops, $index, $block);
    }

    /**
     * @param Op[] $ops
     */
    private function isListDestructDimFetchConsumer(array $ops, int $index, ?Block $block = null): bool
    {
        if ($index + 1 >= count($ops)) {
            return false;
        }
        $fetch = $ops[$index];
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null !== $assignIndex) {
            /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
            $assign = $ops[$assignIndex];
            if ($assign->expr !== $fetch->result) {
                return false;
            }
            // `$c = $s[0]` and `[$c] = $s` share the same CFG shape; only list/array
            // assignment lowering stamps shared attrs (or Array_ kind) onto the fetch (#22646).
            if (!$fetch instanceof Op\Expr\ArrayDimFetch
                || !$this->isListAssignmentLoweredDimFetch($fetch, $assign)) {
                return false;
            }

            return $this->assignIsListDestructSlotTarget($assign->var, $block);
        }
        $next = $ops[$index + 1];

        return $next instanceof Op\Expr\ArrayDimFetch
            && $next->var === $fetch->result
            && $this->isPlainListDestructDimFetch($ops, $index + 1, $block);
    }

    /**
     * True when php-cfg emitted this dim fetch from `list()` / `[] =` assignment, not `$x = $y[$i]`.
     *
     * Short-array destruct carries PhpParser Array_ `kind`; both `list()` and `[]` stamp the same
     * source span on the fetch and its slot assign. Ordinary `$c = $s[0]` uses a narrower fetch span.
     *
     * @see #22646
     */
    private function isListAssignmentLoweredDimFetch(Op\Expr\ArrayDimFetch $fetch, Op $assign): bool
    {
        // Explicit marker from php-cfg parseListAssignment (#22646). Prefer this: Runtime's
        // ParserFactory lexer omits startFilePos/endFilePos, so span equality is unreliable.
        if (true === $fetch->getAttribute('listAssignment') || true === $assign->getAttribute('listAssignment')) {
            return true;
        }
        // Short-array `[$a] = $rhs` also carries PhpParser Array_ kind onto the fetch.
        if (null !== $fetch->getAttribute('kind')) {
            return true;
        }
        $fetchStart = $fetch->getAttribute('startFilePos');
        $assignStart = $assign->getAttribute('startFilePos');
        $fetchEnd = $fetch->getAttribute('endFilePos');
        $assignEnd = $assign->getAttribute('endFilePos');
        if (null === $fetchStart || null === $assignStart || null === $fetchEnd || null === $assignEnd) {
            return false;
        }

        return $fetchStart === $assignStart && $fetchEnd === $assignEnd;
    }

    /**
     * Last CFG op index belonging to one top-level `list()` / `[]` destructuring group (#4325).
     *
     * @param Op[] $ops
     */
    private function listDestructGroupEndIndex(array $ops, int $start, ?Block $block = null): int
    {
        $i = $start;
        if ($this->isListSpreadAssignOp($ops[$i])) {
            return $i;
        }
        while (
            $i < count($ops)
            && ($this->isPlainListDestructDimFetch($ops, $i, $block) || $this->isKeyedListDestructDimFetch($ops, $i, $block))
        ) {
            $i = $this->listDestructOpEndIndex($ops, $i);
        }
        if ($i < count($ops) && $this->isListSpreadAssignOp($ops[$i])) {
            return $i;
        }

        return $i - 1;
    }

    /**
     * @param Op[] $ops
     */
    private function listDestructRhsOperand(array $ops, int $start): Operand
    {
        if ($this->isListSpreadAssignOp($ops[$start])) {
            /** @var Op\Expr\Assign $spread */
            $spread = $ops[$start];

            return $spread->listSpreadRhs;
        }
        /** @var Op\Expr\ArrayDimFetch $firstFetch */
        $firstFetch = $ops[$start];

        return $firstFetch->var;
    }

    /**
     * @param Op[] $ops
     */
    private function listDestructOpEndIndex(array $ops, int $index): int
    {
        /** @var Op\Expr\ArrayDimFetch $fetch */
        $fetch = $ops[$index];
        $assignIndex = $this->listDestructSlotAssignIndex($ops, $index);
        if (null !== $assignIndex) {
            /** @var Op\Expr\Assign|Op\Expr\AssignRef $assign */
            $assign = $ops[$assignIndex];
            if ($assign->expr === $fetch->result) {
                return $assignIndex + 1;
            }
        }
        if ($index + 1 < count($ops)) {
            $next = $ops[$index + 1];
            if ($next instanceof Op\Expr\ArrayDimFetch && $next->var === $fetch->result) {
                return $this->listDestructOpEndIndex($ops, $index + 1);
            }
        }

        return $index + 1;
    }

    /**
     * Guard list destructuring: skip slot assignments when RHS is not an array (#4325); string RHS TypeError (#7461).
     *
     * @param Op[] $ops
     *
     * @return array{0: Block, 1: int}
     */
    private function compileListDestructGroup(array $ops, int $start, Block $block): array
    {
        $this->rejectListDestructuringSpreadAssignProfile($ops, $start, $block);
        $end = $this->listDestructGroupEndIndex($ops, $start, $block);
        $this->rejectListDestructNonWritableWriteTargets($ops, $start, $end, $block);
        $rhs = $this->listDestructRhsOperand($ops, $start);

        $checkOp = new OpCode(
            OpCode::TYPE_LIST_UNPACK_CHECK,
            null,
            $this->compileOperand($rhs, $block, true),
        );
        $block->addOpCode($checkOp);

        for ($j = $start; $j <= $end; ++$j) {
            $this->compileOp($ops[$j], $block);
        }
        $checkOp->listUnpackNullInitSlots = $this->collectListDestructAssignTargetSlots($block, $checkOp);
        $checkOp->listUnpackHasByRef = $this->listDestructGroupHasAssignRef($ops, $start, $block);

        $mergeBlock = new Block($block->orig);
        $mergeBlock->inheritUndefinedLocals = true;
        $mergeBlock->inheritScopeFrom($block);
        $this->inheritFuncFromParent($mergeBlock, $block);
        $checkOp->block1 = $mergeBlock;

        $assignJump = new OpCode(OpCode::TYPE_JUMP);
        $assignJump->block1 = $mergeBlock;
        $block->addOpCode($assignJump);
        $mergeBlock->parents[] = $block;

        return [$mergeBlock, $end];
    }

    /**
     * Named local slots written by guarded list destruct when assign path is skipped (#10591, #10486).
     *
     * @return list<int>
     */
    private function collectListDestructAssignTargetSlots(Block $block, OpCode $checkOp): array
    {
        $slots = [];
        $found = false;
        foreach ($block->opCodes as $op) {
            if ($op === $checkOp) {
                $found = true;
                continue;
            }
            if (!$found) {
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type) {
                break;
            }
            if (OpCode::TYPE_ASSIGN === $op->type || OpCode::TYPE_ASSIGN_REF === $op->type) {
                if (null !== $op->arg2 && $block->isNamedVariableSlot((int) $op->arg2)) {
                    $slots[(int) $op->arg2] = (int) $op->arg2;
                }
                continue;
            }
            if (OpCode::TYPE_LIST_SPREAD_ASSIGN === $op->type) {
                if (null !== $op->arg1 && $block->isNamedVariableSlot((int) $op->arg1)) {
                    $slots[(int) $op->arg1] = (int) $op->arg1;
                }
            }
        }

        return array_values($slots);
    }

    /**
     * Zend zend_compile.c: list spread assign withheld on 8.4.0-dev reference profile (#6936, #17182).
     *
     * @param Op[] $ops
     */
    private function rejectListDestructuringSpreadAssignProfile(array $ops, int $start, ?Block $block = null): void
    {
        $end = $this->listDestructGroupEndIndex($ops, $start, $block);
        for ($i = $start; $i <= $end; ++$i) {
            if (!$this->isListSpreadAssignOp($ops[$i])) {
                continue;
            }
            if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
                $this->throwListSpreadAssignUnsupportedFatal($ops[$i]);
            }
            if (!$this->isListDestructSpreadTail($ops, $i, $block)) {
                $this->throwListSpreadAssignUnsupportedFatal($ops[$i]);
            }
        }
    }

    private function rejectListSpreadAssignExpr(Op\Expr\Assign $expr): void
    {
        if (CompilerVersion::supportsListDestructuringSpreadAssign()) {
            return;
        }
        $this->throwListSpreadAssignUnsupportedFatal($expr);
    }

    /**
     * @param Op\Expr\Assign $spread
     *
     * @return never
     */
    private function throwListSpreadAssignUnsupportedFatal(Op\Expr\Assign $spread): void
    {
        $sourceFile = $spread->getFile() ?? '';
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        throw new CompileFatal(
            $sourceFile,
            max(1, $spread->getLine()),
            'Spread operator is not supported in assignments'
        );
    }

    /**
     * Zend zend_compile_list_assign — destructuring slots with default-value expressions compile-fatal (#14325).
     *
     * php-cfg lowers `[$a = 1] = $rhs` to dim fetch + assign($a, default) + assign($aTemp, fetch).
     *
     * @param Op[] $ops
     *
     * @return never
     */
    private function rejectListDestructDefaultValueSlotsInOps(array $ops): void
    {
        $count = count($ops);
        for ($i = 0; $i < $count; ++$i) {
            $op = $ops[$i];
            if (!$op instanceof Op\Expr\ArrayDimFetch || $i + 1 >= $count) {
                continue;
            }
            $defaultInit = $ops[$i + 1];
            if (!$defaultInit instanceof Op\Expr\Assign || $defaultInit->expr === $op->result) {
                continue;
            }
            if (!$this->isNamedVariableOperand($defaultInit->var)) {
                continue;
            }
            if ($i + 2 < $count && $ops[$i + 2] instanceof Op\Expr\Assign) {
                $chain = $ops[$i + 2];
                if (
                    $chain->expr === $op->result
                    && $this->unwrapOperandChain($chain->var) === $this->unwrapOperandChain($defaultInit->result)
                ) {
                    $this->throwListDestructNonWritableWriteFatalFromOp($defaultInit);
                }
            }
        }
    }

    /**
     * Zend zend_compile.c: list()/[] slots must target writable lvalues (#6691, #7286, #12498).
     *
     * Scan every assign in the destructuring group — php-cfg may emit New/PropertyFetch between
     * the RHS dim fetch and Assign so dim-fetch walking alone misses slots.
     *
     * @param Op[] $ops
     *
     * @return never
     */
    private function rejectListDestructNonWritableWriteTargets(array $ops, int $start, int $end, Block $block): void
    {
        for ($i = $start; $i <= $end; ++$i) {
            $op = $ops[$i];
            if (!$op instanceof Op\Expr\Assign && !$op instanceof Op\Expr\AssignRef) {
                continue;
            }
            if (!$this->assignIsListDestructSlotTarget($op->var, $block)) {
                continue;
            }
            $this->rejectThisReassignment($op->var);
            $this->rejectGlobalsWrite($op->var, $op, $block);
            $this->rejectNullsafeInWriteContext($op->var, $block);
            if (
                !$this->lvalueIsWritableListDestructTarget($op->var, $block)
                || $this->lvalueContainsNewExpr($op->var, $block)
            ) {
                $this->throwListDestructNonWritableWriteFatalFromOp($op);
            }
        }
    }

    /**
     * True when an assign in a list-destruct group writes a slot, not an SSA read temp (#12602).
     */
    private function assignIsListDestructSlotTarget(Operand $var, ?Block $block = null): bool
    {
        if ($var instanceof Operand\Literal) {
            return true;
        }
        if ($var instanceof Op\Expr\ConstFetch || $var instanceof Op\Expr\ClassConstFetch) {
            return true;
        }
        if ($this->isNamedVariableOperand($var)) {
            return true;
        }
        if (null !== $this->unwrapPropertyFetch($var) || null !== $this->unwrapStaticPropertyFetch($var)) {
            return true;
        }
        if (null !== $this->unwrapArrayDimFetch($var)) {
            return true;
        }
        if ($var instanceof Operand\Variable && null !== $block) {
            $producer = $this->findListDestructWriteTargetProducer($var, $block);
            if (null !== $producer) {
                if ($producer instanceof Op\Expr\ArrayDimFetch) {
                    return false;
                }

                return $this->isListDestructWriteTargetPreludeOp($producer);
            }
        }

        return false;
    }

    /**
     * Writable list-destruct slot: variable, property/static-property, or dim fetch on writable base.
     */
    protected function lvalueIsWritableListDestructTarget(?Operand $var, ?Block $block = null): bool
    {
        if (null === $var) {
            return false;
        }
        if ($var instanceof Operand\Temporary && null !== $var->original) {
            return $this->lvalueIsWritableListDestructTarget($var->original, $block);
        }
        if ($var instanceof Op\Expr\ConstFetch || $var instanceof Op\Expr\ClassConstFetch) {
            return false;
        }
        if ($var instanceof Operand\Literal) {
            return false;
        }
        $dimFetch = $this->unwrapArrayDimFetch($var);
        if (null !== $dimFetch) {
            if (null !== $block && $this->operandDerivesFromNew($dimFetch->var, $block)) {
                return false;
            }

            return $this->lvalueIsWritableListDestructTarget($dimFetch->var, $block);
        }
        $propFetch = $this->unwrapPropertyFetch($var);
        if (null !== $propFetch) {
            if (null !== $block && $this->operandDerivesFromNew($propFetch->var, $block)) {
                return false;
            }

            return $this->lvalueIsWritableListDestructTarget($propFetch->var, $block);
        }
        if (null !== $this->unwrapStaticPropertyFetch($var)) {
            return true;
        }
        if (null !== $block) {
            $dimFetch = $this->findArrayDimFetchForResult($var, $block);
            if (null !== $dimFetch) {
                if ($this->operandDerivesFromNew($dimFetch->var, $block)) {
                    return false;
                }

                return $this->lvalueIsWritableListDestructTarget($dimFetch->var, $block);
            }
            $propFetch = $this->findPropertyFetchForResult($var, $block);
            if (null !== $propFetch) {
                if ($this->operandDerivesFromNew($propFetch->var, $block)) {
                    return false;
                }

                return $this->lvalueIsWritableListDestructTarget($propFetch->var, $block);
            }
            if (null !== $this->findStaticPropertyFetchForAssign($var, $block)) {
                return true;
            }
        }
        if ($var instanceof Operand\Variable) {
            if ($this->isNamedVariableOperand($var)) {
                return true;
            }
            if (null !== $block) {
                $producer = $this->findListDestructWriteTargetProducer($var, $block);
                if (null !== $producer) {
                    return $this->listDestructWriteTargetProducerIsWritable($producer, $block);
                }
            }

            return false;
        }

        return false;
    }

    /**
     * php-cfg may bind list slots to inline producer temps (ConstFetch, FuncCall, …) (#12498).
     */
    protected function findListDestructWriteTargetProducer(Operand $var, Block $block): ?Op\Expr
    {
        if (null === $block->orig) {
            return null;
        }
        $root = $this->unwrapOperandChain($var);
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr) {
                continue;
            }
            if ($this->unwrapOperandChain($child->result) === $root) {
                return $child;
            }
        }

        return null;
    }

    protected function listDestructWriteTargetProducerIsWritable(Op\Expr $producer, Block $block): bool
    {
        if ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch) {
            return false;
        }
        if (
            $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\MethodCall
            || $producer instanceof Op\Expr\StaticCall
            || $producer instanceof Op\Expr\New_
        ) {
            return false;
        }
        if ($producer instanceof Op\Expr\BinaryOp) {
            return false;
        }
        if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
            return false;
        }
        if ($producer instanceof Op\Expr\Array_) {
            return false;
        }
        if ($producer instanceof Op\Expr\ArrayDimFetch) {
            if ($this->operandDerivesFromNew($producer->var, $block)) {
                return false;
            }

            return $this->lvalueIsWritableListDestructTarget($producer->var, $block);
        }
        if ($producer instanceof Op\Expr\PropertyFetch) {
            if ($this->operandDerivesFromNew($producer->var, $block)) {
                return false;
            }

            return $this->lvalueIsWritableListDestructTarget($producer->var, $block);
        }
        if ($producer instanceof Op\Expr\StaticPropertyFetch) {
            return true;
        }

        return true;
    }

    /**
     * @return never
     */
    private function throwListDestructNonWritableWriteFatalFromOp(Op $op): void
    {
        $sourceFile = $op->getFile() ?? '';
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        throw new CompileFatal(
            $sourceFile,
            max(1, $op->getLine()),
            'Assignments can only happen to writable values'
        );
    }

    /**
     * @return never
     */
    private function throwListDestructNonWritableWriteFatal(Op\Expr\Assign $assign): void
    {
        $this->throwListDestructNonWritableWriteFatalFromOp($assign);
    }
}
