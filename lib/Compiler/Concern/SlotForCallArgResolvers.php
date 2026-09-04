<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg slot resolution helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see slotForImmediatePropertyOrMethodFetchBeforeCfgCall},
 * {@see slotForInlineClosureProducer}, {@see resolveInlineClosureCallArgSlot},
 * and the intervening init-array / arithmetic / first-class-callable slot
 * helpers they share.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait SlotForCallArgResolvers
{
    /**
     * E::A->name / E::A?->name hoisted as PropertyFetch stmt immediately before consumer FuncCall (#10286).
     */
    private function slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
        Block $block,
        Op $callOp,
        bool $compileIfMissing = true
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $i => $child) {
            if ($child !== $callOp) {
                continue;
            }
            if ($i <= 0) {
                return null;
            }
            $prev = $block->orig->children[$i - 1];
            if (!$prev instanceof Op\Expr\PropertyFetch
                && !$prev instanceof Op\Expr\NullsafePropertyFetch
                && !$prev instanceof Op\Expr\NullsafeMethodCall) {
                return null;
            }
            if ($prev instanceof Op\Expr\PropertyFetch || $prev instanceof Op\Expr\NullsafePropertyFetch) {
                $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prev);
                if (null !== $opcodeSlot) {
                    return (string) $opcodeSlot;
                }
                if ($prev instanceof Op\Expr\NullsafePropertyFetch) {
                    $nullsafeSlot = $this->slotForNullsafeResult($block, $prev);
                    if (null !== $nullsafeSlot) {
                        return (string) $nullsafeSlot;
                    }
                }
            }
            $propertySlot = $block->slotForOperand($prev->result);
            if (null === $propertySlot && $compileIfMissing) {
                foreach ($this->compileExpr($prev, $block) as $op) {
                    $block->addOpCode($op);
                }
                $propertySlot = $block->slotForOperand($prev->result);
            }

            return null !== $propertySlot ? (string) $propertySlot : null;
        }

        return null;
    }

    /**
     * php-cfg dead call-arg temps for hoisted ClassConstFetch (e.g. UnitEnum::class) must not
     * fall through to match-result slot reuse (#9152, is_subclass_of after is_a).
     */
    private function slotForHoistedClassConstFetchCallArg(
        Operand $arg,
        Block $block,
        Op $callOp,
        int $argIndex
    ): ?string {
        if (
            0 === $argIndex
            && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($callOp)
        ) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        if ($this->callArgOperandIsClosureValue($arg, $block)) {
            return null;
        }
        $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall($block, $callOp);
        if (null !== $immediatePropertySlot) {
            return $immediatePropertySlot;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
            return null;
        }
        // Encapsed/concat dead temps must not ordinal-steal a later ClassConstFetch (#22971).
        if ($callArg instanceof Operand && $this->callArgOpsContainConcatList($callArg)) {
            return null;
        }
        // new C(...) — only bind ClassConstFetch onto args that write that fetch (#22971).
        if ($callOp instanceof Op\Expr\New_ && $callArg instanceof Operand) {
            if (null === $this->classConstFetchWriterForNewArg($callArg, $block)) {
                return null;
            }
        }
        if (
            $this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $callOp, $argIndex)
            || $this->nestedFuncCallFeedsDeadInlineCallArg($block, $callOp, $argIndex)
        ) {
            return null;
        }
        $preludeProducer = $this->hoistedPreludeProducerForCallArgIndex($callOp, $argIndex, $block);
        if (null !== $preludeProducer && !$preludeProducer instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        $fetch = $this->precedingClassConstFetchForCallArgIndex(
            $callOp,
            $argIndex,
            $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block)
        );
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            foreach ($block->orig->children as $i => $child) {
                if ($child !== $callOp) {
                    continue;
                }
                if ($i > 0) {
                    $prev = $block->orig->children[$i - 1];
                    $callArg = $callOp->args[$argIndex] ?? null;
                    if (
                        $prev instanceof Op\Expr\ClassConstFetch
                        && null !== $callArg
                        && !(
                            0 === $argIndex
                            && null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                                $callOp,
                                $i,
                                $block->orig->children
                            )
                        )
                        && $this->operandsReferToSameVariable($prev->result, $callArg)
                    ) {
                        $fetch = $prev;
                    }
                }
                break;
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        if ($this->callArgIsNewExpression($callArg)) {
            return null;
        }
        // Foldable class consts (ArrayIterator::ARRAY_AS_PROPS, …) must not reuse an
        // existing operand slot — echo `?:` merge temps often share SSA identity with
        // later ClassConstFetch results and would pass "0"/"1" into `new` (#22576, #5506).
        $folded = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
        if (null !== $folded) {
            return (string) $block->registerConstant(new Operand\Temporary(), $folded);
        }
        $slot = $block->slotForOperand($fetch->result);
        if (null === $slot) {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($fetch->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * `new C(..., Class::CONST)` — materialize foldable ClassConstFetch on a fresh slot so
     * echo `?:` merge temps cannot supply the ctor arg (#22576).
     *
     * Bind only when this New_ arg is written by ClassConstFetch (via `$arg->ops` / result
     * identity). Ordinal mapping across dead temps steals the const onto an earlier encapsed
     * ConcatList arg (`new T("x$v", Class::CONST)` → args `[const, const, …]`, #22971).
     */
    private function slotForFoldedClassConstFetchNewArg(
        Op\Expr\New_ $new,
        int $argIndex,
        Block $block
    ): ?string {
        if (null === $block->orig || !\is_array($new->args) || !isset($new->args[$argIndex])) {
            return null;
        }
        $callArg = $new->args[$argIndex];
        $fetch = $this->classConstFetchWriterForNewArg($callArg, $block);
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        $folded = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
        if (null === $folded) {
            return null;
        }

        return (string) $block->registerConstant(new Operand\Temporary(), $folded);
    }

    /**
     * ClassConstFetch that writes a New_ call-arg temp — never ordinal-steal (#22971, #22576).
     */
    private function classConstFetchWriterForNewArg(Operand $callArg, Block $block): ?Op\Expr\ClassConstFetch
    {
        $writer = $this->soleWriteExprForOperand($callArg);
        if ($writer instanceof Op\Expr\ClassConstFetch) {
            return $writer;
        }
        foreach ($callArg->ops ?? [] as $op) {
            if ($op instanceof Op\Expr\ClassConstFetch) {
                return $op;
            }
        }
        if (null === $block->orig) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\ClassConstFetch
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $callArg)
            ) {
                return $child;
            }
        }

        return null;
    }

    /** True when php-cfg linked this dead call-arg temp to ConcatList / BinaryOp\Concat (#22971). */
    private function callArgOpsContainConcatList(Operand $callArg): bool
    {
        $writer = $this->soleWriteExprForOperand($callArg);
        if ($writer instanceof Op\Expr\ConcatList || $writer instanceof Op\Expr\BinaryOp\Concat) {
            return true;
        }
        foreach ($callArg->ops ?? [] as $op) {
            if ($op instanceof Op\Expr\ConcatList || $op instanceof Op\Expr\BinaryOp\Concat) {
                return true;
            }
        }

        return false;
    }

    /**
     * (new C())->f(E::A) — inline New_ receiver must not steal hoisted enum-case arg slot (#16227).
     */
    /**
     * bindTo(new C(), null) — php-cfg hoists null after New_; prelude wiring must not steal arg #0 (#15900, #16340).
     */
    private function slotForInlineNewClosureBindNewThisArg(
        Block $block,
        Op\Expr\MethodCall $callOp,
        int $argIndex
    ): ?string {
        if (0 !== $argIndex || null === $block->orig) {
            return null;
        }
        $method = $this->staticNameFromOperand($callOp->name);
        if (null === $method || !\in_array(strtolower($method), ['bind', 'bindto'], true)) {
            return null;
        }
        $callArg = $callOp->args[0] ?? null;
        if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return null;
        }
        $newExpr = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\New_) {
                $newExpr = $child;
            }
            break;
        }
        if (!$newExpr instanceof Op\Expr\New_) {
            return null;
        }
        $slot = $block->slotForOperand($newExpr->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return $this->slotForInlineNewProducer($block, $newExpr);
    }

    /**
     * Closure::bind($closure, new C(), null) — hoisted null prelude must not wire to arg #1 ($newThis) (#18880, #15900).
     */
    private function slotForStaticClosureBindNewThisArg(
        Block $block,
        Op\Expr\StaticCall $callOp,
        int $argIndex
    ): ?string {
        if (1 !== $argIndex || null === $block->orig) {
            return null;
        }
        $className = $this->staticNameFromOperand($callOp->class);
        $method = $this->staticNameFromOperand($callOp->name);
        if (null === $className || null === $method) {
            return null;
        }
        if ('closure' !== strtolower(ltrim($className, '\\'))) {
            return null;
        }
        if ('bind' !== strtolower($method)) {
            return null;
        }
        $callArg = $callOp->args[1] ?? null;
        if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $callOp);
        if (!\is_int($callIndex) || $callIndex < 2) {
            return null;
        }
        $newExpr = null;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\New_) {
                $newExpr = $child;
            }
            break;
        }
        if (!$newExpr instanceof Op\Expr\New_) {
            return null;
        }
        $slot = $block->slotForOperand($newExpr->result);
        if (null !== $slot) {
            return (string) $slot;
        }

        return $this->slotForInlineNewProducer($block, $newExpr);
    }

    /**
     * Closure::bind(inline closure, …) — hoisted Enum::class prelude must not wire to arg #0 (#3673, #16722).
     */
    private function slotForStaticClosureBindInlineClosureArg(
        Block $block,
        Op\Expr\StaticCall $callOp,
        int $argIndex
    ): ?string {
        if (0 !== $argIndex || null === $block->orig) {
            return null;
        }
        $className = $this->staticNameFromOperand($callOp->class);
        $method = $this->staticNameFromOperand($callOp->name);
        if (null === $className || null === $method) {
            return null;
        }
        if ('closure' !== strtolower(ltrim($className, '\\'))) {
            return null;
        }
        if (!\in_array(strtolower($method), ['bind', 'fromcallable'], true)) {
            return null;
        }
        $callArg = $callOp->args[0] ?? null;
        if (null === $callArg) {
            return null;
        }
        $producer = null;
        foreach ($block->orig->children as $child) {
            if (
                ($child instanceof Op\Expr\Closure || $child instanceof Op\Expr\ArrowFunction)
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $callArg)
            ) {
                $producer = $child;
                break;
            }
        }
        if (null === $producer) {
            foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp) as $candidate) {
                if (
                    ($candidate instanceof Op\Expr\Closure || $candidate instanceof Op\Expr\ArrowFunction)
                    && null !== $candidate->result
                    && $this->operandsReferToSameVariable($candidate->result, $callArg)
                ) {
                    $producer = $candidate;
                    break;
                }
            }
        }
        if (null === $producer) {
            $callIndex = $this->cfgCallOpIndex($block, $callOp);
            if (\is_int($callIndex)) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $child = $block->orig->children[$i];
                    if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    // bind(inline closure, new C(), Scope::class) — newThis is between scope prelude and call (#17633).
                    if ($child instanceof Op\Expr\New_) {
                        continue;
                    }
                    if ($child instanceof Op\Expr\Closure || $child instanceof Op\Expr\ArrowFunction) {
                        $producer = $child;
                    }
                    break;
                }
            }
        }
        if (!$producer instanceof Op\Expr\Closure && !$producer instanceof Op\Expr\ArrowFunction) {
            return null;
        }
        $slot = $this->slotForInlineClosureProducer($producer, $block);
        if (null === $slot) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $slot = $block->slotForOperand($producer->result);
        }

        return null !== $slot ? (string) $slot : null;
    }

    private function slotForInlineNewMethodCallEnumCaseArg(
        Block $block,
        Op\Expr\MethodCall $callOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig) {
            return null;
        }
        $inlineNewReceiver = false;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\New_ && $this->inlineNewFeedsCallReceiver($child, $callOp)) {
                $inlineNewReceiver = true;
                break;
            }
        }
        if (!$inlineNewReceiver) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (!$this->callArgUsesHoistedEnumPreludeSlot($callArg)) {
            return null;
        }

        return $this->slotForHoistedClassConstFetchCallArg(
            $callArg instanceof Operand ? $callArg : new Temporary(),
            $block,
            $callOp,
            $argIndex
        );
    }

    /** Resolve VM slot for a hoisted inline Closure/ArrowFunction call-arg producer (#3673). */
    /** `$cmp = fn(...); f(..., $cmp)` — bind named locals when php-cfg uses assign-var temps (#5644). */
    private function slotForNamedLocalFromAssignVarOperand(Operand $arg, Block $block): ?int
    {
        if (null !== $block->orig) {
            foreach ($block->orig->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$this->operandsReferToSameVariable($child->var, $arg)) {
                    continue;
                }
                $registered = $block->slotForNamedAssignDest($arg);
                if (null !== $registered) {
                    return $registered;
                }
                if (null !== $child->result) {
                    $namedSlot = $block->slotForOperand($child->result);
                    if (null === $namedSlot) {
                        $namedSlot = $this->slotForEmittedAssignResultSlot($block, $child);
                    }
                    if (null !== $namedSlot) {
                        return (int) $namedSlot;
                    }
                }
            }
        }
        $name = Block::resolveVariableName($arg);
        if (null !== $name && '' !== $name) {
            $paramSlot = $block->paramSlotForName($name);
            if (null !== $paramSlot) {
                return $paramSlot;
            }
            $namedBySlot = $block->slotIndexForVariableName($name);
            if (null !== $namedBySlot) {
                return (int) $namedBySlot;
            }
        }

        return null;
    }

    /** TYPE_ASSIGN arg2 for a registered assign.result temp — the live CV for by-ref sends (#12690). */
    private function slotForAssignLvalueFromResultSlot(Block $block, int $resultSlot): ?int
    {
        $mapped = $block->lvalueSlotForAssignResult($resultSlot);
        if (null !== $mapped) {
            return $mapped;
        }
        // Nested call blocks have no ASSIGN / flagged ITER_VALUE — skip O(opcodes)
        // full scans on every operand read (#36387).
        if (!$block->hasAssignResultScanCandidates()) {
            return null;
        }
        foreach ($block->opCodes as $op) {
            if (
                OpCode::TYPE_ITER_VALUE === $op->type
                && 1 === (int) ($op->arg3 ?? 0)
                && (int) $op->arg1 === $resultSlot
            ) {
                return (int) $op->arg1;
            }
            if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg1 === $resultSlot) {
                return (int) $op->arg2;
            }
        }

        return null;
    }

    /**
     * Assign.result temps diverge from the CV after by-ref builtins; bind the lvalue (#12690, #12712, #12713).
     */
    private function resolveNamedAssignCallArgSlot(
        Block $block,
        int $namedAssignDestSlot,
        ?string $calleeName,
        int $argIndex,
        ?Operand $argProbe
    ): string {
        $lvalue = $this->slotForAssignLvalueFromResultSlot($block, $namedAssignDestSlot);
        if (null !== $lvalue) {
            return (string) $lvalue;
        }

        return (string) $namedAssignDestSlot;
    }

    /** Last TYPE_INIT_ARRAY before the current call — php-cfg dead arg temp vs array literal (#11586). */
    private function slotForRecentInitArrayCallArg(Block $block): ?string
    {
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /**
     * INIT_ARRAY slot after FUNCCALL_INIT / enum prelude opcodes for the active call (#5859).
     */
    private function slotForInitArrayBeforeCurrentFunccall(Block $block): ?string
    {
        $slots = $this->initArraySlotsForCurrentFunccall($block);

        return $slots[0] ?? null;
    }

    /**
     * INIT_ARRAY result slots for the active call — since last FUNCCALL_EXEC_RETURN (#17629).
     *
     * @param list<OpCode> $pendingOps
     *
     * @return list<string>
     */
    private function initArraySlotsForCurrentFunccall(Block $block, array $pendingOps = []): array
    {
        $ops = array_merge($block->opCodes, $pendingOps);
        $start = 0;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $ops[$i]->type) {
                $start = $i + 1;
                break;
            }
        }
        $slots = [];
        for ($i = $start, $n = \count($ops); $i < $n; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_INIT_ARRAY === $op->type && null !== $op->arg1) {
                $slots[] = (string) $op->arg1;
            }
        }

        return $slots;
    }

    /**
     * Result slot of the last TYPE_ARRAY_SPREAD after FUNCCALL_EXEC_RETURN (#24645).
     *
     * Distinguishes `[...new ArrayIterator($ctorArray)]` (spread result) from the ctor
     * Array_ when both appear around the same call-arg wiring.
     *
     * @param list<OpCode> $pendingOps
     */
    private function slotForArraySpreadResultAfterLastExecReturn(Block $block, array $pendingOps = []): ?string
    {
        $ops = array_merge($block->opCodes, $pendingOps);
        $start = 0;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            $type = $ops[$i]->type;
            // EXEC_NORETURN (var_export) must bound like EXEC_RETURN so a later plain
            // array arg is not rewired to a prior [...$x] spread slot (#24645).
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $type
            ) {
                $start = $i + 1;
                break;
            }
        }
        $slot = null;
        for ($i = $start, $n = \count($ops); $i < $n; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_ARRAY_SPREAD === $op->type && null !== $op->arg1) {
                $slot = (string) $op->arg1;
            }
        }

        return $slot;
    }

    /**
     * Rewire sole dead-array ARG_SEND to the ARRAY_SPREAD result after nested New_ (#24645).
     *
     * @param list<OpCode> $argSends
     *
     * @return list<OpCode>
     */
    private function rewriteCallArgSendsForArraySpreadResult(array $argSends, Block $block, ?Op $cfgCallOp): array
    {
        if (null === $cfgCallOp || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return $argSends;
        }
        $spreadSlot = $this->slotForArraySpreadResultAfterLastExecReturn($block, $argSends);
        if (null === $spreadSlot) {
            return $argSends;
        }
        $sendOps = [];
        foreach ($argSends as $op) {
            if ($op instanceof OpCode && OpCode::TYPE_ARG_SEND === $op->type) {
                $sendOps[] = $op;
            }
        }
        if (1 !== \count($sendOps)) {
            return $argSends;
        }
        $callArg = $cfgCallOp->args[0] ?? null;
        if (
            !$callArg instanceof Operand
            || !$this->callArgIsDeadInlineTemporary($callArg)
            || !(
                $this->callArgOperandExpectsArrayProducer($callArg)
                || $this->callArgIsDeadUnknownOrMixedTemporary($callArg)
            )
        ) {
            return $argSends;
        }
        $send = $sendOps[0];
        if ((string) $send->arg1 !== $spreadSlot) {
            $send->arg1 = $spreadSlot;
        }

        return $argSends;
    }

    /**
     * Stmt-before inline Array_ for a call arg — operand slot or ordinal INIT_ARRAY in opcode stream (#16418).
     */
    private function slotForInitArrayProducerBeforeCfgCall(
        Block $block,
        Op $cfgCallOp,
        ?Op\Expr\Array_ $arrayProducer = null,
        array $pendingOps = []
    ): ?string {
        $arrayProducer ??= $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (!$arrayProducer instanceof Op\Expr\Array_) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren) {
            return $block->slotForOperand($arrayProducer->result) !== null
                ? (string) $block->slotForOperand($arrayProducer->result)
                : null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            $slot = $block->slotForOperand($arrayProducer->result);

            return null !== $slot ? (string) $slot : null;
        }
        $targetOrdinal = null;
        $arrayOrdinal = 0;
        for ($i = 0; $i < $callIndex; ++$i) {
            $child = $cfgChildren[$i];
            if ($child instanceof Op\Expr\Array_) {
                if ($child === $arrayProducer) {
                    $targetOrdinal = $arrayOrdinal;
                    break;
                }
                ++$arrayOrdinal;
            }
        }
        if (null !== $targetOrdinal) {
            $seen = 0;
            foreach ($pendingOps as $op) {
                if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                    continue;
                }
                if ($seen === $targetOrdinal) {
                    return (string) $op->arg1;
                }
                ++$seen;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_INIT_ARRAY !== $op->type) {
                    continue;
                }
                if ($seen === $targetOrdinal) {
                    return (string) $op->arg1;
                }
                ++$seen;
            }
        }
        $slot = $block->slotForOperand($arrayProducer->result);

        return null !== $slot ? (string) $slot : null;
    }

    /**
     * CFG branch blocks inherit stale operand slots — wire decoct($x & 0777) to the fresh AND dest (#15902).
     *
     * @param list<OpCode> $emitOps
     */
    private function slotForRecentInlineArithmeticCallArg(Block $block, array $emitOps): ?string
    {
        // Do not cross an intervening call result — e.g. json_encode(iterator_to_array(...))
        // after `new C(..., CONST|CONST)` must not steal the bitmask OR slot (#24369 / #10474).
        $fromOps = $this->slotForRecentInlineArithmeticCallArgInOps($emitOps);
        if (null !== $fromOps) {
            return $fromOps;
        }

        return $this->slotForRecentInlineArithmeticCallArgInOps($block->opCodes);
    }

    /**
     * @param list<OpCode> $ops
     */
    private function slotForRecentInlineArithmeticCallArgInOps(array $ops): ?string
    {
        $skippedCurrentCallInit = false;
        for ($i = \count($ops) - 1; $i >= 0; --$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                if (!$skippedCurrentCallInit) {
                    $skippedCurrentCallInit = true;
                    continue;
                }

                return null;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type
            ) {
                return null;
            }
            if ($this->isInlineArithmeticResultOpcode($op->type)) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    private function isInlineArithmeticResultOpcode(int $type): bool
    {
        return \in_array($type, [
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
        ], true);
    }

    /**
     * Dead inline array call arg: prefer nested FUNCCALL_EXEC_RETURN over sibling INIT_ARRAY (#14042).
     *
     * When php-cfg marks a nested FuncCall result temp dead, compileCallArgSends must not wire the
     * consumer to the nested call's INIT_ARRAY argument slot.
     */
    private function slotForDeadInlineArrayOrCallResultCallArg(Block $block, Op $cfgCallOp, int $argIndex): ?string
    {
        $embeddedArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, $argIndex, $block);
        if ($embeddedArray instanceof Op\Expr\Array_) {
            $embeddedSlot = $block->slotForOperand($embeddedArray->result);
            if (null !== $embeddedSlot) {
                return (string) $embeddedSlot;
            }
        }
        if (!$this->callArgIsNestedFuncCallResult($cfgCallOp, $argIndex, $block)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (
                !(
                    'array_keys' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && $callArg instanceof Operand
                    && $this->callArgIsCoalesceMergeProducer($callArg, $block, $cfgCallOp, $argIndex)
                )
            ) {
                $immediateSlot = $this->slotForInitArrayProducerBeforeCfgCall($block, $cfgCallOp);
                if (null !== $immediateSlot) {
                    return $immediateSlot;
                }
            }

            return $this->slotForRecentInitArrayCallArg($block);
        }
        if (null !== $block->orig) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
            $immediate = $producers[0] ?? null;
            if (
                $immediate instanceof Op\Expr\FuncCall
                || $immediate instanceof Op\Expr\NsFuncCall
                || $immediate instanceof Op\Expr\MethodCall
                || $immediate instanceof Op\Expr\StaticCall
            ) {
                $producerSlot = $block->slotForOperand($immediate->result);
                if (null !== $producerSlot) {
                    return (string) $producerSlot;
                }
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                if (null !== $block->orig) {
                    $callIndex = null;
                    foreach ($block->orig->children as $ci => $child) {
                        if ($child === $cfgCallOp) {
                            $callIndex = $ci;
                            break;
                        }
                    }
                    if (null !== $callIndex) {
                        for ($pi = $callIndex - 1; $pi >= 0; --$pi) {
                            $prior = $block->orig->children[$pi] ?? null;
                            if ($prior instanceof Op\Expr\FuncCall || $prior instanceof Op\Expr\NsFuncCall) {
                                if ($this->statementLevelFuncCallBeforeHoistedSiblingChain(
                                    $pi,
                                    $callIndex,
                                    $block->orig->children
                                )) {
                                    return $this->slotForRecentInitArrayCallArg($block);
                                }
                                break;
                            }
                            if (
                                $prior instanceof Op\Expr\MethodCall
                                || $prior instanceof Op\Expr\StaticCall
                            ) {
                                break;
                            }
                            if (!$prior instanceof Op\Expr\ConstFetch
                                && !$prior instanceof Op\Expr\ClassConstFetch
                                && !$prior instanceof Op\Expr\Array_
                                && !$this->isUnaryInlineSiblingCallArgExpr($prior)
                            ) {
                                break;
                            }
                        }
                    }
                }

                return (string) $op->arg1;
            }
        }

        return $this->slotForRecentInitArrayCallArg($block);
    }

    /**
     * json_encode(nested(), …) — keep outer FUNCCALL_INIT after nested inline producers (#34559).
     *
     * Hoisted JSON_* ConstFetch for flags triggers prependFuncCallInitBeforeTrailingArgConstFetches
     * (#17697) and left outer INIT before inner f()/make_list() under AOT. Dead arg temps may not
     * share cfg roots with the nested FuncCall result, so detect sibling FuncCall producers.
     */
    private function jsonEncodeDeferredInitForNestedCallArg(Op $cfgCallOp, Block $block): bool
    {
        if ('json_encode' !== strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
            return false;
        }
        if ($this->callArgIsNestedFuncCallResult($cfgCallOp, 0, $block)) {
            return true;
        }
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return false;
        }
        $sibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
        if (null === $sibling) {
            return false;
        }
        $flagsArg = $cfgCallOp->args[1] ?? null;
        if (!$flagsArg instanceof Operand) {
            return true;
        }
        $flagsProducer = $this->findCfgProducerExprForOperand($flagsArg);
        if ($flagsProducer instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($flagsProducer->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'JSON_')) {
                return true;
            }
        }
        $prev = $block->orig->children[$callIndex - 1] ?? null;
        if ($prev instanceof Op\Expr\ConstFetch) {
            $name = $this->staticNameFromOperand($prev->name);
            if (null !== $name && str_starts_with(strtoupper($name), 'JSON_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * str_pad()/mb_str_pad(…, nested(), STR_PAD_*) — keep outer FUNCCALL_INIT after nested producers (#34890).
     *
     * Hoisted STR_PAD_* (or user) ConstFetch for pad_type triggers prependFuncCallInitBeforeTrailingArgConstFetches
     * (#17697) and left outer INIT before nested pad_string/encoding FuncCalls under VM — return became the
     * nested call value. Peer {@see jsonEncodeDeferredInitForNestedCallArg} / #34559.
     *
     * CFG may order ConstFetch before the nested FuncCall (`STR_PAD_*` then `enc()` then `mb_str_pad`), so
     * pad_type Operand may be a dead temp — sibling FuncCall detection is the reliable signal.
     */
    private function strPadDeferredInitForNestedCallArg(Op $cfgCallOp, Block $block): bool
    {
        $name = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
        if ('str_pad' !== $name && 'mb_str_pad' !== $name) {
            return false;
        }
        if (null === $block->orig || !\is_array($cfgCallOp->args ?? null)) {
            return false;
        }
        $argc = \count($cfgCallOp->args);
        for ($i = 0; $i < $argc; ++$i) {
            if ($this->callArgIsNestedFuncCallResult($cfgCallOp, $i, $block)) {
                return true;
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex)) {
            return false;
        }

        return null !== $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $block->orig->children);
    }

    /** True when a hoisted nested FuncCall feeds this dead inline call arg (#14042). */
    private function callArgIsNestedFuncCallResult(Op $cfgCallOp, int $argIndex, Block $block): bool
    {
        if (!is_array($cfgCallOp->args ?? null) || null === $block->orig) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (null === $callArg) {
            return false;
        }
        if ($callArg instanceof Operand) {
            $embeddedArray = $this->unwrapArrayLiteralExpr($callArg);
            if (null === $embeddedArray) {
                $producer = $this->findCfgProducerExprForOperand($callArg);
                if ($producer instanceof Op\Expr\Array_) {
                    $embeddedArray = $producer;
                }
            }
            if ($embeddedArray instanceof Op\Expr\Array_) {
                return false;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        foreach ($producers as $producer) {
            if (
                $producer instanceof Op\Expr\Array_
                && $this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArg)
            ) {
                return false;
            }
        }
        foreach ($producers as $producer) {
            if (
                !$producer instanceof Op\Expr\FuncCall
                && !$producer instanceof Op\Expr\NsFuncCall
                && !$producer instanceof Op\Expr\StaticCall
                && !$producer instanceof Op\Expr\MethodCall
            ) {
                continue;
            }
            if ($this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArg)) {
                return true;
            }
        }
        $immediate = $producers[0] ?? null;
        if (
            (
                $immediate instanceof Op\Expr\FuncCall
                || $immediate instanceof Op\Expr\NsFuncCall
                || $immediate instanceof Op\Expr\StaticCall
                || $immediate instanceof Op\Expr\MethodCall
            )
            && (int) $argIndex > 0
            && $this->callArgIsDeadInlineTemporary($callArg)
            && $this->callArgOperandExpectsArrayProducer($callArg)
        ) {
            return true;
        }

        return false;
    }

    /**
     * FUNCCALL_EXEC_RETURN immediately before hoisted ConstFetch prelude — nested subject (#13617).
     *
     * compileCallArgSends runs before FUNCCALL_INIT is appended, so the tail is often CONST_FETCH
     * with the nested call's EXEC_RETURN one slot earlier (filter_var(sprintf(...), FILTER_*)).
     */
    private function slotForNestedSubjectExecBeforeLiteralPreludeCall(Block $block): ?string
    {
        $ops = $block->opCodes;
        $n = \count($ops);
        if ($n < 2) {
            return null;
        }
        $execIndex = null;
        $tail = $ops[$n - 1];
        if (
            OpCode::TYPE_CONST_FETCH === $tail->type
            || OpCode::TYPE_CLASS_CONST_FETCH === $tail->type
        ) {
            $execIndex = $n - 2;
        } elseif (OpCode::TYPE_FUNCCALL_INIT === $tail->type) {
            if ($n < 3) {
                return null;
            }
            $beforeInit = $ops[$n - 2];
            if (
                OpCode::TYPE_CONST_FETCH !== $beforeInit->type
                && OpCode::TYPE_CLASS_CONST_FETCH !== $beforeInit->type
            ) {
                return null;
            }
            $execIndex = $n - 3;
        } elseif (
            OpCode::TYPE_STATICCALL_INIT === $tail->type
            || OpCode::TYPE_METHODCALL_INIT === $tail->type
        ) {
            if ($n < 3) {
                return null;
            }
            $beforeInit = $ops[$n - 2];
            if (
                OpCode::TYPE_CONST_FETCH !== $beforeInit->type
                && OpCode::TYPE_CLASS_CONST_FETCH !== $beforeInit->type
            ) {
                return null;
            }
            $execIndex = $n - 3;
        } else {
            return null;
        }
        $exec = $ops[$execIndex] ?? null;
        if (null === $exec || OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $exec->type) {
            return null;
        }

        return (string) $exec->arg1;
    }

    /**
     * Nested FuncCall subject immediately before hoisted literal preludes (filter_var(sprintf(...), FILTER_*); #13617).
     */
    private function nestedInlineFuncCallProducerForCallArg(Block $block, Op $cfgCallOp, int $argIndex): ?Op\Expr
    {
        if (null === $block->orig) {
            return null;
        }
        if ($argIndex > 0 && is_array($cfgCallOp->args ?? null)) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if (null !== $callArg) {
                foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
                    if (
                        !$producer instanceof Op\Expr\FuncCall
                        && !$producer instanceof Op\Expr\NsFuncCall
                        && !$producer instanceof Op\Expr\StaticCall
                        && !$producer instanceof Op\Expr\MethodCall
                    ) {
                        continue;
                    }
                    if (
                        null !== $producer->result
                        && (
                            $producer->result === $callArg
                            || $this->operandsReferToSameVariable($producer->result, $callArg)
                        )
                    ) {
                        return $producer;
                    }
                }
            }

            return null;
        }
        $consumerIndex = null;
        foreach ($block->orig->children as $ci => $child) {
            if ($child === $cfgCallOp) {
                $consumerIndex = $ci;
                break;
            }
        }
        if (null === $consumerIndex) {
            return null;
        }
        foreach ($this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp) as $producer) {
            if (
                !$producer instanceof Op\Expr\FuncCall
                && !$producer instanceof Op\Expr\NsFuncCall
                && !$producer instanceof Op\Expr\StaticCall
                && !$producer instanceof Op\Expr\MethodCall
            ) {
                continue;
            }
            $producerIndex = null;
            foreach ($block->orig->children as $pi => $child) {
                if ($child === $producer) {
                    $producerIndex = $pi;
                    break;
                }
            }
            if (null === $producerIndex) {
                continue;
            }
            if ($this->isNestedCallArgProducerForConsumer(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $consumerIndex,
                $block->orig->children
            ) || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $consumerIndex,
                $block->orig->children
            )) {
                return $producer;
            }
        }

        return null;
    }

    /** Last TYPE_CLOSURE before the current call — php-cfg dead arg temp vs inline arrow fn (#11586). */
    private function slotForRecentClosureCallArg(Block $block): ?string
    {
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /** Resolve assign.result slot from emitted TYPE_ASSIGN when cfg temps lack scope bindings (#5644). */
    private function slotForEmittedAssignResultSlot(Block $block, Op\Expr\Assign $assign): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $assignOrdinal = 0;
        $targetOrdinal = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Assign) {
                if ($child === $assign) {
                    $targetOrdinal = $assignOrdinal;
                    break;
                }
                ++$assignOrdinal;
            }
        }
        if (null === $targetOrdinal) {
            return null;
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (int) $op->arg1;
            }
            ++$seen;
        }

        return null;
    }

    /** `$cmp = fn(...); f(..., $cmp)` — use the named local, not the dead closure temp (#5644). */
    private function slotForNamedClosureLocalFromProducer(Op\Expr $producer, Block $block): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        if (
            !$producer instanceof Op\Expr\ArrowFunction
            && !$producer instanceof Op\Expr\Closure
        ) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->assignExprMatchesClosureProducer($child->expr, $producer)) {
                continue;
            }
            if (!$this->exprDerivesFromClosure($child->expr)) {
                continue;
            }
            if (null !== $child->result) {
                $namedSlot = $block->slotForOperand($child->result);
                if (null !== $namedSlot) {
                    return (int) $namedSlot;
                }
            }
            $namedSlot = $block->slotForOperand($child->var);
            if (null !== $namedSlot && $block->isNamedVariableSlot((int) $namedSlot)) {
                return (int) $namedSlot;
            }
        }

        return null;
    }

    private function slotForInlineClosureProducer(Op\Expr $producer, Block $block): ?int
    {
        if (null === $producer->result) {
            return null;
        }
        $namedLocal = $this->slotForNamedClosureLocalFromProducer($producer, $block);
        if (null !== $namedLocal) {
            return $namedLocal;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (
                OpCode::TYPE_STATICCALL_INIT === $op->type
                || OpCode::TYPE_FUNCCALL_INIT === $op->type
            ) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $destSlot = (int) $op->arg1;
                $destOperand = $block->operandForScopeSlot($destSlot);
                if (
                    null !== $destOperand
                    && $this->operandsReferToSameVariable($destOperand, $producer->result)
                ) {
                    return $destSlot;
                }
            }
        }
        foreach ($this->compileExpr($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Resolve VM slot for a hoisted inline first-class callable call-arg producer (#9769, zend_compile.c). */
    private function slotForInlineFirstClassCallableProducer(
        Op\Expr\FirstClassCallable $producer,
        Block $block
    ): ?int {
        if (null === $producer->result) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FROM_CALLABLE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        foreach ($this->compileFirstClassCallable($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Inline `E::A->m(...)` call args must send the Closure result, not enum-case prefetch slots (#9769). */
    private function resolveInlineFirstClassCallableCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        ?int $knownArgIndex = null
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callOp = $cfgCallOp;
        $argIndex = $knownArgIndex;
        if (null === $knownArgIndex) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $foundArgIndex] = $callSite;
                $argIndex = $foundArgIndex;
            }
        }
        if (null === $argIndex) {
            return null;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $funcName = $this->resolveInlineCallArgFuncName($callOp);
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        if ($callbackArgIndex >= 0 && 2 === \count($callOp->args) && $argIndex !== $callbackArgIndex) {
            return null;
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $funcName
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }
        $leadingFcc = null;
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null !== $callIndex) {
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                // php-cfg children can be sparse (undefined keys) — skip holes (#36387 Doctor).
                $prev = $block->orig->children[$i] ?? null;
                if (!$prev instanceof Op) {
                    continue;
                }
                if ($prev instanceof Op\Expr\FirstClassCallable) {
                    $leadingFcc = $prev;
                    break;
                }
                if ($prev instanceof Op\Expr\Assign) {
                    if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                        break;
                    }
                    continue;
                }
                if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                    continue;
                }
                if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                    break;
                }
            }
        }
        if ($leadingFcc instanceof Op\Expr\FirstClassCallable) {
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $leadingFcc,
                $callOp->args,
                $argIndex,
                $funcName
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }
        if (1 === count($callOp->args)) {
            $last = $producers[\count($producers) - 1] ?? null;
            if ($last instanceof Op\Expr\FirstClassCallable) {
                return $this->slotForInlineFirstClassCallableProducer($last, $block);
            }
        }
        if (0 === $this->inlineClosureArrayPairCallbackArgIndex($funcName) && 0 === $argIndex) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $scanOp = $block->opCodes[$i];
                if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                    break;
                }
                if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                    return (int) $scanOp->arg1;
                }
            }
        }

        return null;
    }

    /** StaticCall inline closure first arg — match hoisted Closure producer to TYPE_CLOSURE slot (#3673). */
    private function resolveInlineClosureCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            $argRoot = $this->unwrapOperandChain($arg);
            if ($argRoot instanceof Op\Expr\ArrowFunction || $argRoot instanceof Op\Expr\Closure) {
                return $this->slotForInlineClosureProducer($argRoot, $block);
            }

            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (null !== $callArg && $this->isNamedVariableOperand($callArg)) {
            return null;
        }
        if (null !== $callArg) {
            $callArgRoot = $this->unwrapOperandChain($callArg);
            if ($callArgRoot instanceof Op\Expr\ArrowFunction || $callArgRoot instanceof Op\Expr\Closure) {
                $directSlot = $this->slotForInlineClosureProducer($callArgRoot, $block);
                if (null !== $directSlot) {
                    return $directSlot;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block, $calleeName);
        if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
            return $this->slotForInlineClosureProducer($producer, $block);
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\Closure && !$candidate instanceof Op\Expr\ArrowFunction) {
                continue;
            }
            if (null !== $this->matchSingleClosureInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $this->resolveInlineCallArgFuncName($callOp, $calleeName)
            )) {
                return $this->slotForInlineClosureProducer($candidate, $block);
            }
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $this->resolveInlineCallArgFuncName($callOp)
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }

        return null;
    }

    /**
     * Inline fn()/function() callback args with trailing literal flags (#10232, #9154).
     */
    private function resolvePrecedingClosureCallArgSlot(
        Op $cfgCallOp,
        int $argIndex,
        Block $block,
        ?string $calleeName = null
    ): ?int {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->isNamedVariableOperand($callArg)) {
            return null;
        }
        if ($this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        if (
            \count($callArgs) < 2
            && !$this->cfgCallAcceptsSingleInlineClosureCallback($cfgCallOp)
        ) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $callbackProducer = null;
        foreach ($producers as $candidate) {
            if ($candidate instanceof Op\Expr\ArrowFunction
                || $candidate instanceof Op\Expr\Closure
                || $candidate instanceof Op\Expr\FirstClassCallable) {
                $callbackProducer = $candidate;
                break;
            }
        }
        if (null === $callbackProducer) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $prev = $block->orig->children[$i] ?? null;
                    if (!$prev instanceof Op) {
                        continue;
                    }
                    if ($prev instanceof Op\Expr\ArrowFunction
                        || $prev instanceof Op\Expr\Closure
                        || $prev instanceof Op\Expr\FirstClassCallable) {
                        $callbackProducer = $prev;
                        break;
                    }
                    if ($prev instanceof Op\Expr\Assign) {
                        if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                            break;
                        }
                        continue;
                    }
                    if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                        continue;
                    }
                    if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                        break;
                    }
                }
            }
        }
        if (null === $callbackProducer) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $matched = $callbackProducer instanceof Op\Expr\FirstClassCallable
            ? $this->matchSingleFirstClassCallableInlineProducer(
                $callbackProducer,
                $callArgs,
                $argIndex,
                $funcName
            )
            : $this->matchSingleClosureInlineProducer(
                $callbackProducer,
                $callArgs,
                $argIndex,
                $funcName
            );
        if (null === $matched) {
            $matched = $this->matchInlineCallArgProducer($producers, $callArgs, $argIndex, $cfgCallOp, $block, $calleeName);
            if ($matched !== $callbackProducer) {
                return null;
            }
        }
        if ($callbackProducer instanceof Op\Expr\FirstClassCallable) {
            $fccSlot = $this->slotForInlineFirstClassCallableProducer($callbackProducer, $block);
            if (null !== $fccSlot) {
                return $fccSlot;
            }
        } else {
            $slot = $this->slotForInlineClosureProducer($callbackProducer, $block);
            if (null !== $slot) {
                return $slot;
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $scanOp = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                break;
            }
            if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                return (int) $scanOp->arg1;
            }
            if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                return (int) $scanOp->arg1;
            }
        }

        return null;
    }
}
