<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\Type;

/**
 * Expression-prelude, ArrayDimFetch chain, and hoisted ConstFetch call-arg slots (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers preceding expression-prelude result slots, chained ArrayDimFetch producers,
 * pending FUNC_CALL / EVAL call-arg dim-fetch wiring, and hoisted scalar/enum
 * ClassConstFetch dead-prelude matching used from compileCallArgSends.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as ExactHoistedAndInlineNewCallArgProducers).
 */
trait ExpressionPreludeDimFetchAndHoistedConstCallArgSlots
{
    /**
     * var_export($text->data) / var_export($expr instanceof T) — immediate PropertyFetch/compare prelude (#17540).
     */
    private function resolvePrecedingExpressionPreludeCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp || 0 !== $argIndex) {
            return null;
        }
        // The prelude read below is children[$callIndex - 1] — the TRAILING argument's producer.
        // Handing it to arg #0 of a multi-argument call is exactly backwards: f($x + 1, $r['k'])
        // printed "K|K" (#23354). Only valid when arg #0 IS the trailing non-embedded argument.
        if (0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? $arg;
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        $prelude = $block->orig->children[$callIndex - 1] ?? null;
        if (
            !$prelude instanceof Op\Expr
            || !$this->isImmediateVarExportExpressionPrelude($prelude)
            || null === $prelude->result
        ) {
            return null;
        }
        // Multi-arg ctor/call with trailing scalar/flag prelude — do not bind to arg #0 (#19735, #19738).
        // Covers BitwiseOr, Plus/Mul/shifts, UnaryMinus, Cast, etc. (isTrailingInlineNewCtorOptionPrelude).
        if (
            $this->isTrailingInlineNewCtorOptionPrelude($prelude)
            && 0 !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
        ) {
            return null;
        }
        $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        if (null === $opcodeSlot) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
        }
        if (null !== $opcodeSlot) {
            return (string) $opcodeSlot;
        }
        $slot = $block->slotForOperand($prelude->result);
        if (null !== $slot) {
            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude);
            if (null !== $opcodeSlot && $opcodeSlot !== $slot) {
                return (string) $opcodeSlot;
            }

            return (string) $slot;
        }

        return null;
    }

    /**
     * Operand slot map can lag TYPE_PROPERTY_FETCH / TYPE_INSTANCEOF when php-cfg reuses dead temps (#17540).
     */
    private function compiledExpressionPreludeResultSlotBeforePendingFuncCall(
        Block $block,
        Op\Expr $prelude
    ): ?int {
        $expectedTypes = match (true) {
            $prelude instanceof Op\Expr\PropertyFetch => [OpCode::TYPE_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\NullsafePropertyFetch => [OpCode::TYPE_NULLSAFE],
            $prelude instanceof Op\Expr\StaticPropertyFetch => [OpCode::TYPE_STATIC_PROPERTY_FETCH],
            $prelude instanceof Op\Expr\ArrayDimFetch => [OpCode::TYPE_ARRAY_DIM_FETCH, OpCode::TYPE_ARRAY_DIM_FETCH_WRITE],
            $prelude instanceof Op\Expr\InstanceOf_ => [OpCode::TYPE_INSTANCEOF],
            $prelude instanceof Op\Expr\Cast => [
                OpCode::TYPE_CAST_ARRAY,
                OpCode::TYPE_CAST_BOOL,
                OpCode::TYPE_CAST_FLOAT,
                OpCode::TYPE_CAST_INT,
                OpCode::TYPE_CAST_OBJECT,
                OpCode::TYPE_CAST_STRING,
                OpCode::TYPE_CAST_UNSET,
                OpCode::TYPE_CAST_VOID,
            ],
            $prelude instanceof Op\Expr\BooleanNot => [OpCode::TYPE_BOOLEAN_NOT],
            $prelude instanceof Op\Expr\BitwiseNot => [OpCode::TYPE_BITWISE_NOT],
            $prelude instanceof Op\Expr\UnaryMinus => [OpCode::TYPE_UNARY_MINUS],
            $prelude instanceof Op\Expr\UnaryPlus => [OpCode::TYPE_UNARY_PLUS],
            // Typed property ++/-- inline call-arg (#26491 / re-#10123, zend_execute.c).
            $prelude instanceof Op\Expr\PostInc => [OpCode::TYPE_POST_INC],
            $prelude instanceof Op\Expr\PreInc => [OpCode::TYPE_PRE_INC],
            $prelude instanceof Op\Expr\PostDec => [OpCode::TYPE_POST_DEC],
            $prelude instanceof Op\Expr\PreDec => [OpCode::TYPE_PRE_DEC],
            $this->isComparisonInlineCallArgProducer($prelude) => [OpCode::TYPE_IDENTICAL, OpCode::TYPE_NOT_IDENTICAL, OpCode::TYPE_EQUAL, OpCode::TYPE_NOT_EQUAL, OpCode::TYPE_SPACESHIP, OpCode::TYPE_SMALLER, OpCode::TYPE_GREATER, OpCode::TYPE_SMALLER_OR_EQUAL, OpCode::TYPE_GREATER_OR_EQUAL, OpCode::TYPE_INSTANCEOF, OpCode::TYPE_IN],
            $this->isArithmeticInlineCallArgProducer($prelude) => [
                OpCode::TYPE_BITWISE_AND,
                OpCode::TYPE_BITWISE_OR,
                OpCode::TYPE_BITWISE_XOR,
                OpCode::TYPE_PLUS,
                OpCode::TYPE_MINUS,
                OpCode::TYPE_MUL,
                OpCode::TYPE_DIV,
                OpCode::TYPE_MODULO,
                OpCode::TYPE_POW,
                OpCode::TYPE_SHIFT_LEFT,
                OpCode::TYPE_SHIFT_RIGHT,
            ],
            default => [],
        };
        if ([] === $expectedTypes) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (\in_array($op->type, $expectedTypes, true)) {
                return $op->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Pending callee INIT/ARG_SEND during compileCallArgSends — skip to hoisted prelude (#14467, #17540).
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_ARG_SEND === $op->type) {
                continue;
            }
        }

        return null;
    }

    /**
     * var_dump((['a'=>1])['a']) — php-cfg dead arg temp; Array_ + ArrayDimFetch immediately precede call (#16462).
     */
    private function resolveInlineArrayLiteralDimFetchCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || $argIndex < 0) {
            return null;
        }
        $callArg = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        // Embedded literals (e.g. call_user_func_array('fn', [&$x])) are not dim-fetch producers (#18015).
        if ($this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex || $callIndex < 1) {
            return null;
        }
        // children[$callIndex - 1] is the TRAILING argument's fetch; handing it to every index made
        // f($x + 1, $r['k']) print "K|K" (#23354).
        if ($argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return null;
        }
        $fetch = $block->orig->children[$callIndex - 1] ?? null;
        if (!$fetch instanceof Op\Expr\ArrayDimFetch) {
            return null;
        }
        // Array-literal by-ref element setup (FETCH_DIM_W + ASSIGN_REF) is not a dim-read call arg (#18015).
        if ($this->isArrayDimFetchForWrite($fetch, $block)) {
            return null;
        }
        $array = $callIndex >= 2 ? ($block->orig->children[$callIndex - 2] ?? null) : null;
        if (
            !$array instanceof Op\Expr\Array_
            || !$this->operandsReferToSameVariable($fetch->var, $array->result)
        ) {
            return null;
        }
        if (null === $block->slotForOperand($fetch->result)) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($fetch->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * Hoisted dim-fetch on a method-call receiver must not bind to call args (#9703).
     */
    private function arrayDimFetchFeedsMethodCallReceiver(
        Op\Expr\ArrayDimFetch $fetch,
        ?Operand $receiver
    ): bool {
        if (null === $receiver) {
            return false;
        }
        if (
            null !== $fetch->result
            && (
                $fetch->result === $receiver
                || $this->operandsReferToSameVariable($fetch->result, $receiver)
            )
        ) {
            return true;
        }
        $root = $this->unwrapOperandChain($receiver);
        if (!$root instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        $current = $root;
        while ($current instanceof Op\Expr\ArrayDimFetch) {
            if (
                $current === $fetch
                || (
                    null !== $fetch->result
                    && null !== $current->result
                    && $this->operandsReferToSameVariable($fetch->result, $current->result)
                )
            ) {
                return true;
            }
            $current = $this->unwrapOperandChain($current->var);
        }

        return false;
    }

    /**
     * var_export($a[1][0], true) — chained hoisted dim-fetch tail feeds arg #0 only (#15762, #15945).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchChainedArrayDimFetchInlineCallArgProducer(array $producers, int $argIndex): ?Op\Expr
    {
        // Nested dim chain before isset()/empty() is a quiet prelude, not the call arg (#21991).
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Isset_ || $producer instanceof Op\Expr\Empty_) {
                return null;
            }
        }
        $dimFetches = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ArrayDimFetch
        ));
        if (
            \count($dimFetches) < 2
            || !$this->arrayDimFetchesFormProducerChain($dimFetches)
        ) {
            return null;
        }
        if (0 === $argIndex) {
            return $dimFetches[\count($dimFetches) - 1];
        }
        $nonDimProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => !$producer instanceof Op\Expr\ArrayDimFetch
        ));

        return $nonDimProducers[$argIndex - 1] ?? null;
    }

    /**
     * Consecutive hoisted dim-fetch preludes before one call arg — $a[0]['k'] (#14555).
     *
     * @param list<Op\Expr\ArrayDimFetch> $dimFetches
     */
    private function arrayDimFetchesFormProducerChain(array $dimFetches): bool
    {
        if (\count($dimFetches) < 2) {
            return false;
        }
        for ($i = 1; $i < \count($dimFetches); ++$i) {
            $inner = $dimFetches[$i];
            $outer = $dimFetches[$i - 1];
            if (
                null === $inner->var
                || null === $outer->result
                || !$this->operandsReferToSameVariable($inner->var, $outer->result)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Operand slot map can lag TYPE_ARRAY_DIM_FETCH when php-cfg reuses result temps (#10401).
     *
     * @param list<OpCode> $opcodes
     *
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(array $opcodes, int $dimIndex = 0): ?int
    {
        $dimFetchOpcodes = [];
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            $op = $opcodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            // Write lvalues (TYPE_ARRAYACCESS_OFFSET) are not dim-fetch read results (#10639).
            if (OpCode::TYPE_ARRAY_DIM_FETCH !== $op->type) {
                if ([] !== $dimFetchOpcodes) {
                    break;
                }
                continue;
            }
            array_unshift($dimFetchOpcodes, $op);
        }
        if (!isset($dimFetchOpcodes[$dimIndex])) {
            return null;
        }

        return $dimFetchOpcodes[$dimIndex]->arg1;
    }

    /**
     * @return int|null VM slot from the Nth dim-fetch opcode before the pending FUNCCALL_INIT
     */
    private function compiledArrayDimFetchResultSlotBeforePendingFuncCall(Block $block, int $dimIndex = 0): ?int
    {
        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes($block->opCodes, $dimIndex);
    }

    /**
     * Pending call-arg opcodes may hold the haystack dim-fetch before FUNCCALL_INIT lands on the block (#17000).
     *
     * @param list<OpCode> $pendingOps
     */
    private function pendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps, int $dimIndex = 0): ?int
    {
        if ([] === $pendingOps) {
            return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $dimIndex);
        }

        return $this->compiledArrayDimFetchResultSlotBeforePendingFuncCallFromOpcodes(
            array_merge($block->opCodes, $pendingOps),
            $dimIndex
        );
    }

    /**
     * Last ARRAY_DIM_FETCH (read) before pending FUNCCALL_INIT — var_export($meta['k'], …) after earlier dim assigns (#18005).
     * Exclude TYPE_ARRAY_DIM_FETCH_WRITE: write lvalues must not feed call args (#10639).
     *
     * @param list<OpCode> $pendingOps
     */
    private function lastPendingCallArgArrayDimFetchSlot(Block $block, array $pendingOps): ?int
    {
        $dimFetchOpcodes = [];
        $merged = array_merge($block->opCodes, $pendingOps);
        for ($i = \count($merged) - 1; $i >= 0; --$i) {
            $op = $merged[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                break;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $op->type) {
                array_unshift($dimFetchOpcodes, $op);
            }
        }
        if ([] === $dimFetchOpcodes) {
            return null;
        }
        $last = $dimFetchOpcodes[\count($dimFetchOpcodes) - 1];

        return null !== $last->arg1 ? (int) $last->arg1 : null;
    }

    /**
     * Nested inline consumer — last FUNCCALL_EXEC_RETURN before trailing FUNCCALL_INIT (#14555).
     */
    private function slotForLastEmittedInlineCallResultBeforePendingFuncCall(Block $block): ?int
    {
        return $block->lastFunccallExecReturnSlot();
    }

    /**
     * Pending call-arg opcodes — nested FUNCCALL_EXEC_RETURN not yet on the block (#9292).
     *
     * @param list<OpCode> $opcodes
     */
    private function slotForLastPendingInlineCallResultBeforeFuncCallInit(array $opcodes): ?int
    {
        for ($i = \count($opcodes) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $opcodes[$i]->type) {
                return (int) $opcodes[$i]->arg1;
            }
            if (OpCode::TYPE_FUNCCALL_INIT === $opcodes[$i]->type) {
                break;
            }
        }

        return null;
    }

    /**
     * Last FUNCCALL_EXEC_RETURN on block plus pending call-arg opcodes (#10474, is_array(file(..., FLAGS))).
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForLastInlineFuncCallExecReturn(Block $block, array $pendingOps = []): ?int
    {
        $last = $block->lastFunccallExecReturnSlot();
        foreach ($pendingOps as $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && null !== $op->arg1) {
                $last = (int) $op->arg1;
            }
        }

        return $last;
    }

    /**
     * php-cfg dead call-arg temp for inline eval() — TYPE_EVAL producer slot (#10661, zif_eval).
     */
    private function resolvePrecedingEvalCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $matchedIndex] = $callSite;
        if ($matchedIndex !== $argIndex) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $matched = $this->matchInlineCallArgProducer($producers, $callOp->args ?? [], $argIndex, $callOp);
        if (!$matched instanceof Op\Expr\Eval_) {
            return null;
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_EVAL === $op->type) {
                return (string) $op->arg1;
            }
        }
        if (null === $block->slotForOperand($matched->result)) {
            foreach ($this->compileExpr($matched, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($matched->result);

        return null !== $slot ? (string) $slot : null;
    }

    protected function operandHasObjectType(Operand $operand): bool
    {
        $operand = $this->unwrapOperandChain($operand);

        return null !== $operand->type && Type::TYPE_OBJECT === $operand->type->type;
    }

    /**
     * php-cfg may linearize `E::A; E::B; foo($a, $b)` into dead ClassConstFetch stmts
     * plus distinct call-arg temporaries with no dataflow edge (#5933, #5858).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingClassConstFetchesBeforeCfgOp(array $cfgChildren, Op $callOp): array
    {
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex) {
            return [];
        }
        $fetches = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i];
            if ($child instanceof Op\Expr\ClassConstFetch) {
                array_unshift($fetches, $child);

                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
            if ($child instanceof Op\Expr && $this->isInlineExprCallArgProducer($child)) {
                continue;
            }
            break;
        }

        return $fetches;
    }

    /**
     * Call-arg slot mapping must skip enum case fetches that only feed `Case::class` (#9426).
     *
     * @param list<Op\Expr\ClassConstFetch> $fetches
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function dropEnumCaseFetchesConsumedByCaseClassPseudoConst(
        array $fetches,
        array $cfgChildren,
        Op $beforeOp,
        Block $block
    ): array {
        if ([] === $fetches) {
            return $fetches;
        }
        $stopIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $beforeOp) {
                $stopIndex = $i;
                break;
            }
        }
        if (null === $stopIndex) {
            return $fetches;
        }
        $filtered = [];
        foreach ($fetches as $fetch) {
            if (!$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)) {
                $filtered[] = $fetch;
                continue;
            }
            $consumed = false;
            for ($i = 0; $i < $stopIndex; ++$i) {
                $child = $cfgChildren[$i];
                if (!$child instanceof Op\Expr\ClassConstFetch) {
                    continue;
                }
                $pseudoName = $this->staticNameFromOperand($child->name);
                if (null === $pseudoName || 'class' !== strtolower($pseudoName)) {
                    continue;
                }
                if ($this->operandsReferToSameVariable($child->class, $fetch->result)) {
                    $consumed = true;
                    break;
                }
            }
            if (!$consumed) {
                $filtered[] = $fetch;
            }
        }

        return $filtered;
    }

    /**
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function precedingCallArgClassConstFetchesBeforeCfgOp(
        array $cfgChildren,
        Op $callOp,
        Block $block
    ): array {
        $fetches = $this->precedingClassConstFetchesBeforeCfgOp($cfgChildren, $callOp);

        return $this->dropEnumCaseFetchesConsumedByCaseClassPseudoConst($fetches, $cfgChildren, $callOp, $block);
    }

    /**
     * php-cfg may hoist `E::A; E::B; f(E::A); g(E::B)` to dead ClassConstFetch stmts before the
     * first call; later calls then lack a preceding fetch (#4260, #5933, ext/standard/type.c).
     */
    private function classConstFetchForHoistedDeadPrelude(
        Op $callOp,
        int $argIndex,
        Block $block
    ): ?Op\Expr\ClassConstFetch {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $firstCallIndex = null;
        foreach ($children as $i => $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                $firstCallIndex = $i;
                break;
            }
        }
        if (null === $firstCallIndex || $callIndex <= $firstCallIndex) {
            return null;
        }
        /** @var list<Op\Expr\ClassConstFetch> $hoistedFetches */
        $hoistedFetches = [];
        for ($i = 0; $i < $firstCallIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\ClassConstFetch
                && !$this->hoistedEnumCaseFetchConsumedInCfg($child, $block)
            ) {
                $hoistedFetches[] = $child;
            }
        }
        if ([] === $hoistedFetches) {
            return null;
        }
        $callsBefore = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                ++$callsBefore;
            }
        }
        $slotOrdinal = $this->hoistedEnumPreludeSlotOrdinalForCallArg($callOp, $argIndex);
        if (null === $slotOrdinal) {
            return null;
        }
        $fetchIndex = $callsBefore + $slotOrdinal;

        return $hoistedFetches[$fetchIndex] ?? null;
    }

    /**
     * Map call ordinal + arg index to a ClassConstFetch when php-cfg linearizes fetches (#4260).
     */
    private function enumConstFetchForCallOrdinal(Block $block, int $callOrdinal, int $argIndex): ?Op\Expr\ClassConstFetch
    {
        if (null === $block->orig) {
            return null;
        }
        $children = $block->orig->children;
        $targetCall = null;
        $ordinal = 0;
        foreach ($children as $child) {
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                if ($ordinal === $callOrdinal) {
                    $targetCall = $child;
                    break;
                }
                ++$ordinal;
            }
        }
        if (null === $targetCall) {
            return null;
        }
        $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($children, $targetCall, $block);

        return $this->precedingClassConstFetchForCallArgIndex($targetCall, $argIndex, $fetches);
    }

    /**
     * @return array{0: Op, 1: int}|null
     */
    private function findCfgCallSiteForArg(array $cfgChildren, Operand $arg, ?Op $knownCallOp = null): ?array
    {
        $argRoot = Block::cfgVarRoot($arg);
        $argChain = $this->unwrapOperandChain($arg);
        if (
            null !== $knownCallOp
            && property_exists($knownCallOp, 'args')
            && is_array($knownCallOp->args)
        ) {
            foreach ($knownCallOp->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$knownCallOp, $argIndex];
                }
            }
        }
        foreach ($cfgChildren as $child) {
            if (!property_exists($child, 'args') || !is_array($child->args)) {
                continue;
            }
            foreach ($child->args as $argIndex => $callArg) {
                if ($this->cfgCallArgOperandsMatch($callArg, $arg, $argChain, $argRoot)) {
                    return [$child, $argIndex];
                }
            }
        }

        return null;
    }

    private function cfgCallArgOperandsMatch(
        Operand $callArg,
        Operand $arg,
        Operand $argChain,
        ?Operand $argRoot
    ): bool {
        if ($callArg === $arg) {
            return true;
        }
        if ($this->unwrapOperandChain($callArg) === $argChain) {
            return true;
        }

        return null !== $argRoot && Block::cfgVarRoot($callArg) === $argRoot;
    }

    /**
     * php-cfg hoists null/false/true ConstFetch before FuncCall with dead arg temps (#9140, #15931, #16065).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchHoistedScalarConstFetchInlineCallArgProducer(array $producers, ?Operand $callArg): ?Op\Expr\ConstFetch
    {
        if (null === $callArg || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\ConstFetch || null === $producer->result) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($producer->result, $callArg)) {
                continue;
            }
            $name = $this->staticNameFromOperand($producer->name);
            if (null === $name || !\in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                continue;
            }

            return $producer;
        }

        return null;
    }

    /** Stmt immediately before FuncCall is hoisted true/false/null for a trailing call arg (#11407). */
    private function isHoistedScalarConstFetchImmediatelyBeforeCall(?Op $expr): bool
    {
        if (!$expr instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($expr->name);

        return null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true);
    }

    /**
     * php-cfg hoists ConstFetch/ClassConstFetch immediately before FuncCall for dead inline arg temps.
     * Defer eager compileOps so FUNCCALL_INIT runs first (php-src undefined-function before undefined-const, #17697).
     *
     * @param Op[] $ops
     */
    private function isDeferredHoistedConstFetchCallArgPrelude(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (
            !$fetch instanceof Op\Expr\ConstFetch
            && !$fetch instanceof Op\Expr\ClassConstFetch
        ) {
            return false;
        }
        // Sibling comparison operands (false !== ini_get(...)) are not call args — compile eagerly (#17756, #17757).
        if ($this->hoistedConstFetchFeedsSiblingComparisonAfterCall($fetch, $consumer, $ops, $fetchIndex)) {
            return false;
        }
        if (!isset($fetch->result)) {
            return false;
        }
        if (!property_exists($consumer, 'args') || !\is_array($consumer->args)) {
            return false;
        }
        // php-cfg hoists call-arg ConstFetch as the stmt immediately before the consumer (#17697).
        foreach ($consumer->args as $arg) {
            if (null === $arg) {
                continue;
            }
            if ($arg === $fetch->result || $this->operandsReferToSameVariable($arg, $fetch->result)) {
                return true;
            }
        }
        // array_chunk(range(...), 2, true) — php-cfg dead temps may not share cfg roots (#11767).
        if (
            $fetch instanceof Op\Expr\ConstFetch
            && ($ops[$fetchIndex + 1] ?? null) === $consumer
        ) {
            $name = $this->staticNameFromOperand($fetch->name);
            if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                foreach ($consumer->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when a hoisted fetch supplies a comparison operand after the adjacent FuncCall, not a call arg.
     *
     * @param Op[] $ops
     */
    private function hoistedConstFetchFeedsSiblingComparisonAfterCall(
        Op\Expr $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $consumer,
        array $ops,
        int $fetchIndex
    ): bool {
        if (null === $fetch->result || ($ops[$fetchIndex + 1] ?? null) !== $consumer) {
            return false;
        }
        for ($j = $fetchIndex + 2, $n = \count($ops); $j < $n; ++$j) {
            $stmt = $ops[$j];
            if (!$this->isComparisonInlineCallArgProducer($stmt) || !$stmt instanceof Op\Expr\BinaryOp) {
                break;
            }
            if (
                $this->operandsReferToSameVariable($stmt->left, $fetch->result)
                || $this->operandsReferToSameVariable($stmt->right, $fetch->result)
            ) {
                return true;
            }
        }

        return false;
    }

    private function slotForInlineArrayExpr(Block $block, ?Op\Expr\Array_ $arrayExpr): ?string
    {
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileArrayLiteral($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }

    /** Outermost hoisted Array_ stmt immediately before a cfg FuncCall (#11485). */
    private function resolveInlineArrayProducerSlotBeforeCfgCall(Op $callOp, Block $block): ?string
    {
        $arrayExpr = $this->inlineArrayProducerImmediatelyBeforeCfgCall($callOp, $block);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            return null;
        }
        if (null === $block->slotForOperand($arrayExpr->result)) {
            foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($arrayExpr->result);

        return null !== $slot ? (string) $slot : null;
    }
}
