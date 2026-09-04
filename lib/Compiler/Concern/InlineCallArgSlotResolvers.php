<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline call-arg slot resolution and producer filtering (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see findMatchResultVarForDeadCallArg}, closure/init-array/class-const
 * call-arg slot helpers, and nested/cast/array inline producer match filters.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SiblingInlineCallArgProducerSlots).
 */
trait InlineCallArgSlotResolvers
{
    /**
     * php-cfg match lowering seeds a shared var, arms assign to it, merge uses dead arg temp (#9374).
     */
    private function findMatchResultVarForDeadCallArg(
        Operand $arg,
        CfgBlock $cfgBlock,
        Op $callOp
    ): ?Operand {
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        // UnhandledMatchError helpers take the match *subject*, never the seeded result temp.
        // Remapping `$v` / expr subjects onto the null seed yields "Unhandled match case NULL" (#24329, #5448).
        $callee = strtolower($this->resolveCfgFuncCallName($callOp) ?? '');
        if (
            'phpc_match_unhandled_operand_message' === $callee
            || 'phpc_match_unhandled_operand_is_object' === $callee
            || 'phpc_match_unhandled_format_scalar' === $callee
        ) {
            return null;
        }
        // Named CVs are never the dead match-result phi temp (#24329).
        if (null !== Block::resolveVariableName($arg)) {
            return null;
        }
        $isCallArg = false;
        foreach ($callOp->args as $callArg) {
            if ($callArg === $arg || $this->operandsReferToSameVariable($callArg, $arg)) {
                $isCallArg = true;
                break;
            }
        }
        if (!$isCallArg) {
            return null;
        }
        foreach ($cfgBlock->children as $child) {
            if (
                $child instanceof Op\Expr
                && property_exists($child, 'result')
                && null !== $child->result
                && $this->operandsReferToSameVariable($child->result, $arg)
            ) {
                return null;
            }
        }
        // Match subject may be produced in the jump parent (ClassConstFetch) while this block
        // only calls phpc_match_unhandled_operand_is_object($cond) — do not reuse result slot (#5448).
        foreach ($cfgBlock->parents as $parent) {
            if (!$this->cfgBlockJumpsToCfgBlock($parent, $cfgBlock)) {
                continue;
            }
            foreach ($parent->children as $child) {
                if (
                    $child instanceof Op\Expr
                    && property_exists($child, 'result')
                    && null !== $child->result
                    && $this->operandsReferToSameVariable($child->result, $arg)
                ) {
                    return null;
                }
                // Subject is Identical left operand in the arm test block (#24329).
                if (
                    $child instanceof Op\Expr\BinaryOp\Identical
                    && property_exists($child, 'left')
                    && null !== $child->left
                    && $this->operandsReferToSameVariable($child->left, $arg)
                ) {
                    return null;
                }
            }
        }
        if (!isset($cfgBlock->parents) || [] === $cfgBlock->parents) {
            return null;
        }
        $matchVar = null;
        foreach ($cfgBlock->parents as $parent) {
            if (!$this->cfgBlockJumpsToCfgBlock($parent, $cfgBlock)) {
                continue;
            }
            foreach ($parent->children as $child) {
                if (!$child instanceof Op\Expr\Assign) {
                    continue;
                }
                if (!$child->var instanceof CfgVariable && !$child->var instanceof Temporary) {
                    continue;
                }
                // Default-only match keeps seed+arm assigns in the same parent as
                // preceding named locals (`$x = 1`). Those are not the match result
                // temp — skipping them restores the shared phi slot for ARG_SEND (#23984).
                if (null !== Block::resolveVariableName($child->var)) {
                    continue;
                }
                if (null === $matchVar) {
                    $matchVar = $child->var;
                    continue;
                }
                if (!$this->operandsReferToSameVariable($matchVar, $child->var)) {
                    return null;
                }
            }
        }

        return $matchVar;
    }

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

    private function cfgBlockJumpsToCfgBlock(CfgBlock $from, CfgBlock $to): bool
    {
        foreach ($from->children as $child) {
            if ($child instanceof Op\Stmt\Jump && $child->target === $to) {
                return true;
            }
            if ($child instanceof Op\Stmt\JumpIf && ($child->if === $to || $child->else === $to)) {
                return true;
            }
        }

        return false;
    }

    private function slotForMatchResultDeadCallArg(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp] = $callSite;
        $matchVar = $this->findMatchResultVarForDeadCallArg($arg, $block->orig, $callOp);
        if (null === $matchVar) {
            return null;
        }
        $slot = $block->slotForOperand($matchVar);
        if (null === $slot) {
            $slot = $this->compileOperand($matchVar, $block, true);
        }

        return null !== $slot ? (string) $slot : null;
    }


    /** Drop void MethodCall preludes before a sibling MethodCall inline producer (#10778). */
    private function filterKnownVoidMethodCallPreludes(array $producers): array
    {
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if (
                $producer instanceof Op\Expr\MethodCall
                && null !== ($method = $this->staticNameFromOperand($producer->name))
                && $this->methodCallIsKnownVoidReturn($method)
                && ($producers[$i + 1] ?? null) instanceof Op\Expr\MethodCall
            ) {
                continue;
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * Drop statement-level StaticCall preludes when StaticPropertyFetch siblings cover call args (#34997).
     *
     * php-cfg: `A::inc(); A::inc(); var_dump(A::$n, B::$n)` lists the void StaticCalls before the
     * fetches in the inline producer walk; ordinal ARG_SEND wiring then steals the void returns.
     * `var_dump(Foo::a(), Foo::b())` keeps its StaticCalls (no StaticPropertyFetch siblings).
     *
     * @param list<Op\Expr>      $producers
     * @param list<Operand|null> $callArgs
     *
     * @return list<Op\Expr>
     */
    private function filterStmtLevelStaticCallBeforeStaticPropertyFetchProducers(
        array $producers,
        array $callArgs
    ): array {
        $fetchCount = 0;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\StaticPropertyFetch) {
                ++$fetchCount;
            }
        }
        $deadTempArgCount = 0;
        foreach ($callArgs as $arg) {
            if ($arg instanceof Operand && $this->callArgIsDeadInlineTemporary($arg)) {
                ++$deadTempArgCount;
            }
        }
        if ($fetchCount < 1 || $fetchCount < $deadTempArgCount || $deadTempArgCount < 1) {
            return $producers;
        }
        $filtered = [];
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\StaticCall) {
                // Statement A::inc() before shared-static multi-arg reads — not var_dump(Foo::a(), Foo::b()).
                continue;
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * Drop stmt-level array pointer mutators before a sibling FuncCall producer (#13829, ext/standard/array.c).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterStmtLevelArrayPointerFuncPreludes(array $producers): array
    {
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && $this->isArrayInternalPointerMutatorFuncName($this->resolveCfgFuncCallName($producer))
                && (
                    ($producers[$i + 1] ?? null) instanceof Op\Expr\FuncCall
                    || ($producers[$i + 1] ?? null) instanceof Op\Expr\NsFuncCall
                )
            ) {
                continue;
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /** next/prev/reset/end/pos — stmt-level pointer advance, not var_export/var_dump arg producers (#13829). */
    private function isArrayInternalPointerMutatorFuncName(?string $name): bool
    {
        if (null === $name) {
            return false;
        }

        return \in_array(strtolower($name), ['next', 'prev', 'reset', 'end', 'pos'], true);
    }

    /**
     * Drop `(new C())` preludes when php-cfg lowers `(new C())->prop` as separate inline producers (#8874).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterNestedNewInlineCallArgProducers(array $producers, ?Op $consumer = null): array
    {
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if (
                $producer instanceof Op\Expr\New_
                && null !== $consumer
                && $this->inlineNewFeedsCallReceiver($producer, $consumer)
            ) {
                continue;
            }
            if ($producer instanceof Op\Expr\New_) {
                $next = $producers[$i + 1] ?? null;
                if (
                    ($next instanceof Op\Expr\PropertyFetch
                        || $next instanceof Op\Expr\MethodCall
                        || $next instanceof Op\Expr\NullsafeMethodCall)
                    && property_exists($next, 'var')
                    && $next->var instanceof Operand
                    && $this->operandsReferToSameVariable($next->var, $producer->result)
                ) {
                    continue;
                }
                // f((string) new C()) — php-cfg dead arg temp; Cast consumes New_ (#9504).
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
                // id(clone new C()) — New_ prelude feeds Clone_, not the call arg (#13687).
                if (
                    $next instanceof Op\Expr\Clone_
                    && property_exists($next, 'expr')
                    && $next->expr instanceof Operand
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
                // array_fill_keys([new C()], 1) — New_ prelude is array element, not the keys arg (#10849).
                if (
                    $next instanceof Op\Expr\Array_
                    && property_exists($next, 'values')
                    && \is_array($next->values)
                ) {
                    foreach ($next->values as $entryValue) {
                        if (
                            $entryValue instanceof Operand
                            && $this->operandsReferToSameVariable($entryValue, $producer->result)
                        ) {
                            continue 2;
                        }
                    }
                }
            }
            // array_merge((object)[...], [...]) — Array_ prelude feeds Cast, not the call (#15207).
            if ($producer instanceof Op\Expr\Array_) {
                $next = $producers[$i + 1] ?? null;
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
            }
            // var_export((int) E::A) — ClassConstFetch prelude feeds Cast, not the call arg (#9479, #15982).
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $next = $producers[$i + 1] ?? null;
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
            }
            // f((int) SOME_CONST) — ConstFetch prelude feeds Cast, not the call arg (#10143).
            if ($producer instanceof Op\Expr\ConstFetch) {
                $next = $producers[$i + 1] ?? null;
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->operandsReferToSameVariable($next->expr, $producer->result)
                ) {
                    continue;
                }
            }
            // array_intersect(str_split(str_repeat(...)), ...) — inner g() unary hoisted arg (#15488).
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $next = $producers[$i + 1] ?? null;
                if (
                    ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                    && property_exists($next, 'args')
                    && \is_array($next->args)
                ) {
                    $hoistedInNext = 0;
                    foreach ($next->args as $nextArg) {
                        if (
                            null !== $nextArg
                            && !$this->isEmbeddedCallLiteralArg($nextArg)
                            && $this->callArgIsDeadInlineTemporary($nextArg)
                        ) {
                            ++$hoistedInNext;
                        }
                    }
                    if (1 === $hoistedInNext) {
                        // Only discard when $producer is the inner g() feeding f(g()) — not adjacent sibling
                        // producers (array_diff_assoc(array_keys(...), array_keys(...)), #15571, #13779).
                        $discardProducer = false;
                        if (null !== $producer->result) {
                            foreach ($next->args as $nextArg) {
                                if (
                                    null !== $nextArg
                                    && $this->operandsReferToSameVariable($producer->result, $nextArg)
                                ) {
                                    $discardProducer = true;
                                    // array_combine(array_keys(...), [...]) / array_merge(array_keys(...), …) —
                                    // keep nested FuncCall producer for inline-call arg wiring (#15553, #15551, #13776, #12450).
                                    $nextCallee = $this->resolveCfgFuncCallName($next);
                                    if (
                                        \in_array($nextCallee, ['array_combine', 'array_merge', 'array_merge_recursive'], true)
                                    ) {
                                        $discardProducer = false;
                                    }
                                    break;
                                }
                            }
                        }
                        if ($discardProducer) {
                            continue;
                        }
                    }
                }
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * php-cfg dead call-arg temps for array union may alias a Plus operand, not Plus.result (#10490, #12763).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchArrayUnionPlusInlineCallArgProducer(
        array $producers,
        Operand $callArg,
        int $argCount
    ): ?Op\Expr {
        if ([] === $producers) {
            return null;
        }
        $last = $producers[\count($producers) - 1];
        if (!$last instanceof Op\Expr\BinaryOp\Plus) {
            return null;
        }
        if ($this->operandsReferToSameVariable($last->result, $callArg)) {
            return $last;
        }
        if (1 !== $argCount || !$this->callArgIsDeadInlineTemporary($callArg)) {
            return null;
        }
        $arrayLiteralCount = 0;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Array_) {
                ++$arrayLiteralCount;
            }
        }
        if ($arrayLiteralCount >= 2) {
            return $last;
        }
        foreach ([$last->left, $last->right] as $operand) {
            if (null !== $operand && $this->operandsReferToSameVariable($operand, $callArg)) {
                return $last;
            }
        }
        foreach ($producers as $producer) {
            if (
                null !== $producer->result
                && $this->operandsReferToSameVariable($producer->result, $callArg)
            ) {
                return $last;
            }
        }

        return null;
    }

    /**
     * Sibling nested inline Array_ literals — map each arg to its outermost producer (#12729, #10230).
     *
     * e.g. array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]])
     * — producers [inner Array_, outer Array_, inner Array_, outer Array_].
     *
     * @param list<Op\Expr> $producers
     */
    private function matchSiblingNestedArrayLiteralCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount
    ): ?Op\Expr {
        $producerCount = \count($producers);
        if ($argCount < 2 || $producerCount <= $argCount) {
            return null;
        }
        if (!$this->producersAreNestedArrayLiteralChain($producers)) {
            return null;
        }
        if (0 !== $producerCount % $argCount) {
            return null;
        }
        $depth = intdiv($producerCount, $argCount);
        if ($depth < 2) {
            return null;
        }
        for ($g = 0; $g < $argCount; ++$g) {
            $group = \array_slice($producers, $g * $depth, $depth);
            if (!$this->arrayProducersFormNestedChain($group)) {
                return null;
            }
        }
        $mappedIndex = $argIndex * $depth + ($depth - 1);

        return $producers[$mappedIndex] ?? null;
    }

    /**
     * php-cfg may omit the first nested arg's inner Array_ when folding into its outer (#10230, #12729).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchFoldedFirstNestedSiblingArrayLiteralCallArgProducer(
        array $producers,
        int $argIndex,
        int $argCount,
        array $callArgs = []
    ): ?Op\Expr {
        if (2 !== $argCount || 3 !== \count($producers)) {
            return null;
        }
        $outer0 = $producers[0] ?? null;
        $inner1 = $producers[1] ?? null;
        $outer1 = $producers[2] ?? null;
        if (
            !$outer0 instanceof Op\Expr\Array_
            || !$inner1 instanceof Op\Expr\Array_
            || !$outer1 instanceof Op\Expr\Array_
        ) {
            return null;
        }
        if (!$this->arrayProducersFormNestedChain([$inner1, $outer1])) {
            return null;
        }
        if ($this->cfgExprUsesOperand($inner1, $outer0->result)) {
            return null;
        }
        // array_column([[..]], 'col') — single nested haystack + embedded scalar (#13703).
        if (
            $argIndex > 0
            && isset($callArgs[$argIndex])
            && !$this->callArgOperandExpectsArrayProducer($callArgs[$argIndex])
        ) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($callArgs);
        if (null !== $soleHoisted && 0 === $soleHoisted && 0 === $argIndex) {
            return $outer1;
        }

        return 0 === $argIndex ? $outer0 : $outer1;
    }

    /**
     * php-cfg dead call-arg temps that share a result with an inline BitwiseOr/Array_ producer (#11407, #11586).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchDirectResultInlineCallArgProducer(array $producers, Operand $callArg): ?Op\Expr
    {
        foreach (array_reverse($producers) as $producer) {
            if (null === $producer->result) {
                continue;
            }
            // php-cfg parseArg clones leave distinct temps with producer listed in Temporary->ops (#22753).
            $opsMatch = isset($callArg->ops)
                && \is_array($callArg->ops)
                && \in_array($producer, $callArg->ops, true);
            if (!$this->operandsReferToSameVariable($producer->result, $callArg) && !$opsMatch) {
                continue;
            }
            if (
                $producer instanceof Op\Expr\BinaryOp\BitwiseOr
                || $producer instanceof Op\Expr\BinaryOp\BitwiseAnd
                || $producer instanceof Op\Expr\BinaryOp\BitwiseXor
            ) {
                // Array builtins must not bind to int bitmask OR (#10474, is_array(file(..., FLAGS))).
                if (!$this->callArgOperandExpectsArrayProducer($callArg)) {
                    return $producer;
                }
                continue;
            }
            if (
                $producer instanceof Op\Expr\Array_
                && $this->callArgOperandExpectsArrayProducer($callArg)
            ) {
                return $producer;
            }
            // var_export(C::__set_state([]), true) — arg #0 is StaticCall/MethodCall, not nested Array_ (#11896).
            if (
                $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
            ) {
                return $producer;
            }
            // array_merge((object)[...], [...]) — Cast producer feeds arg #0 (#15207).
            if ($producer instanceof Op\Expr\Cast) {
                return $producer;
            }
            // var_export(!$o, true) / var_dump(~$x) — unary not feeds arg #0 (#26702, #10537).
            if ($producer instanceof Op\Expr\BooleanNot || $producer instanceof Op\Expr\BitwiseNot) {
                return $producer;
            }
            // E::A->name / E::A?->name / M::X?->id() — property/method fetch result is the call arg (#9684, #10286).
            if ($producer instanceof Op\Expr\PropertyFetch
                || $producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall) {
                return $producer;
            }
        }

        return null;
    }

    /**
     * var_export([array_any([], fn), array_all([], fn)]) — prefer embedded Array_ over sibling FuncCall (#14516).
     */
    private function preferEmbeddedArrayLiteralOverSiblingFuncCallMatch(
        ?Op\Expr $matched,
        Op $cfgCallOp,
        int $argIndex,
        Block $block,
        Operand $callArgProbe
    ): ?Op\Expr {
        if (
            ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall)
            && $this->callArgOperandExpectsArrayProducer($callArgProbe)
        ) {
            $callee = strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '');
            if (
                0 === $argIndex
                && (
                    'array_combine' === $callee
                    || \in_array($callee, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                )
            ) {
                return $matched;
            }
            $embeddedArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, $argIndex, $block);
            if ($embeddedArray instanceof Op\Expr\Array_) {
                return $embeddedArray;
            }
        }

        return $matched;
    }

    /**
     * array_merge((object)[...], [...]) — hoisted Cast feeds arg #0, not stmt-before Array_ (#15858).
     * array_walk((object)[...], fn) — same for by-ref arg #0 (#15874).
     *
     * Nested casts inside a later array arg must not claim arg #0:
     * array_replace_recursive(['a'=>['b'=>1]], ['a'=>(object)['c'=>2]]) (#25098).
     *
     * @param list<Op\Node> $cfgChildren
     */
    private function inlineCallArgZeroFedByHoistedCastProducer(array $cfgChildren, Op $callOp): bool
    {
        if (!\in_array(
            $this->resolveCfgFuncCallName($callOp),
            [
                'array_merge',
                'array_merge_recursive',
                'array_replace',
                'array_replace_recursive',
                'array_diff',
                'array_intersect',
                'array_diff_key',
                'array_intersect_key',
                'array_walk',
                'array_walk_recursive',
            ],
            true
        )) {
            return false;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $callOp);
        $callArg0 = $callOp->args[0] ?? null;
        foreach ($producers as $producer) {
            if (
                $producer instanceof Op\Expr\Cast
                && $this->hoistedCastFeedsCallArgZero($producer, $callArg0, $producers)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a hoisted Cast is the top-level value of call arg #0 — not an array element (#25098).
     *
     * @param list<Op\Expr> $producers
     */
    private function hoistedCastFeedsCallArgZero(Op\Expr\Cast $cast, mixed $callArg0, array $producers): bool
    {
        if (
            $callArg0 instanceof Operand
            && null !== $cast->result
            && $this->operandsReferToSameVariable($cast->result, $callArg0)
        ) {
            return true;
        }
        // Cast embedded as an Array_ value (nested (object)[...]) is not arg #0 (#25098).
        if (null !== $cast->result) {
            foreach ($producers as $producer) {
                if (
                    $producer instanceof Op\Expr\Array_
                    && $this->cfgExprUsesOperand($producer, $cast->result)
                ) {
                    return false;
                }
            }
        }

        // Top-level (object)[...] — Cast precedes trailing Array_ args; operand identity may differ (#15858).
        return true;
    }

    /**
     * array_merge((object)[...], [...]) — wire hoisted Cast result to arg #0 (#15207, #15858).
     * array_walk((object)[...], fn) — same for by-ref arg #0 (#15874).
     * array_keys((array)$ao) — Cast over ctor INIT_ARRAY stolen by array_keys Array_ path (#28822).
     */
    private function resolveHoistedCastInlineCallArgZeroSlot(
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName,
        int $argIndex
    ): ?string {
        if (0 !== $argIndex || null === $cfgCallOp) {
            return null;
        }
        $callee = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        // null callee: variable `$fn((object)…)` still needs Cast→arg0 (#15858).
        // Reject only when the name is a known non-allowlisted string (#22894 object invoke
        // has null name and is filtered by contiguous-producer scan below).
        if (
            null !== $callee
            && !\in_array(
                $callee,
                [
                    'array_merge',
                    'array_merge_recursive',
                    'array_replace',
                    'array_replace_recursive',
                    'array_diff',
                    'array_intersect',
                    'array_diff_key',
                    'array_intersect_key',
                    'array_walk',
                    'array_walk_recursive',
                    // array_keys((array)$ao) — prefer Cast over ctor INIT_ARRAY (#28822).
                    'array_keys',
                ],
                true
            )
        ) {
            return null;
        }
        $callArg0 = $cfgCallOp->args[0] ?? null;
        // Only dead inline temps from `(object)[...]` / casts — never literals or CVs (#22894).
        if (
            !$callArg0 instanceof Operand
            || $this->isEmbeddedCallLiteralArg($callArg0)
            || !$this->callArgIsDeadInlineTemporary($callArg0)
        ) {
            return null;
        }
        $cfgChildren = $this->inlineCallArgProducerCfgChildren($block);
        if ([] === $cfgChildren && null !== $block->orig) {
            $cfgChildren = $block->orig->children;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (null === $callIndex) {
            $containing = $this->findCfgBlockContainingExpr($cfgCallOp);
            if (null !== $containing) {
                $cfgChildren = $containing->children;
                foreach ($cfgChildren as $i => $child) {
                    if ($child === $cfgCallOp) {
                        $callIndex = $i;
                        break;
                    }
                }
            }
        }
        if (null === $callIndex || [] === $cfgChildren) {
            return null;
        }
        // Only contiguous inline producers immediately before the call (Echo/Terminal breaks).
        // Stmt-level `(string)$obj` then `$obj($arg)` must not steal the Cast (#22894).
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($cfgChildren, $cfgCallOp);
        $cast = null;
        // Prefer the Cast nearest the call (last in oldest-first producers) so
        // array_keys((array)(object)[...]) binds Array_ cast, not Object_ (#28822).
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\Cast) {
                $cast = $producer;
            }
        }
        if (null === $cast) {
            // array_merge((object)[...], [...]) — Cast may sit behind immediate Array_ (#15858).
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                $child = $cfgChildren[$i] ?? null;
                if ($child instanceof Op\Expr\Array_ && $i === $callIndex - 1) {
                    continue;
                }
                if ($child instanceof Op\Expr\Cast) {
                    $cast = $child;
                    break;
                }
                break;
            }
        }
        if (null === $cast) {
            return null;
        }
        if (!$this->hoistedCastFeedsCallArgZero($cast, $callArg0, $producers)) {
            // Nested (object) inside a later array arg — do not steal arg #0 (#25098).
            return null;
        }
        if (null === $block->slotForOperand($cast->result)) {
            foreach ($this->compileExpr($cast, $block) as $op) {
                $block->addOpCode($op);
            }
        }
        $slot = $block->slotForOperand($cast->result);

        return null !== $slot ? (string) $slot : null;
    }

    private function inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
        Op $cfgCallOp,
        int $argIndex,
        Block $block
    ): bool {
        if (null === $block->orig) {
            return false;
        }
        if (
            0 === $argIndex
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['array_column', 'preg_replace_callback_array'],
                true
            )
        ) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (
            $callArg instanceof Operand
            && $this->callArgOperandExpectsArrayProducer($callArg)
            && $this->callArgIsDeadInlineTemporary($callArg)
        ) {
            $immediate = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
            if ($immediate instanceof Op\Expr\Array_) {
                if (
                    0 === $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                ) {
                    // array_merge(array_keys(...), [...]) — trailing Array_ is arg #1 (#12450, #16418).
                } else {
                    return false;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        if ([] === $producers) {
            return false;
        }
        $producerMatch = $this->matchInlineCallArgProducer(
            $producers,
            $cfgCallOp->args ?? [],
            $argIndex,
            $cfgCallOp,
            $block,
            $this->resolveCfgFuncCallName($cfgCallOp)
        );

        return $producerMatch instanceof Op\Expr\FuncCall
            || $producerMatch instanceof Op\Expr\NsFuncCall
            || $producerMatch instanceof Op\Expr\MethodCall
            || $producerMatch instanceof Op\Expr\StaticCall
            || $producerMatch instanceof Op\Expr\Cast;
    }

    /**
     * var_export(C::__set_state([]), true) — nested Array_ is not arg #0 when a sibling call feeds the dead temp (#11896).
     *
     * @param list<Op\Expr> $producers
     */
    private function preferSiblingCallOverNestedArrayInlineMatch(
        ?Op\Expr $matched,
        array $producers,
        ?Operand $callArgProbe
    ): ?Op\Expr {
        if (
            !$matched instanceof Op\Expr\Array_
            || null === $callArgProbe
            || $this->callArgOperandExpectsArrayProducer($callArgProbe)
        ) {
            return $matched;
        }
        foreach ($producers as $candidate) {
            if (
                ($candidate instanceof Op\Expr\StaticCall
                    || $candidate instanceof Op\Expr\MethodCall
                    || $candidate instanceof Op\Expr\FuncCall
                    || $candidate instanceof Op\Expr\NsFuncCall)
                && null !== $candidate->result
                && $this->operandsReferToSameVariable($candidate->result, $callArgProbe)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * php-cfg dead temps for `var_export(C::AR[0] === E::X, true)` — Identical feeds arg 0, not ClassConstFetch (#5901, #9660).
     *
     * @param list<Op\Expr> $producers
     */
    private function matchBooleanBinaryOpInlineCallArgProducer(array $producers, Operand $callArg): ?Op\Expr
    {
        $comparisonProducers = [];
        foreach ($producers as $producer) {
            if (!$this->isComparisonInlineCallArgProducer($producer)) {
                continue;
            }
            if ($this->operandsReferToSameVariable($producer->result, $callArg)) {
                return $producer;
            }
            $comparisonProducers[] = $producer;
        }
        if (1 === \count($comparisonProducers) && $this->callArgIsDeadInlineTemporary($callArg)) {
            return $comparisonProducers[0];
        }

        return null;
    }

    /** Hoisted compare/spaceship/relational Expr feeds a call arg, not an inner operand (#13694, #13945). */
    private function isComparisonInlineCallArgProducer(mixed $expr): bool
    {
        if (!$expr instanceof Op\Expr) {
            return false;
        }

        return $expr instanceof Op\Expr\BinaryOp\Identical
            || $expr instanceof Op\Expr\BinaryOp\NotIdentical
            || $expr instanceof Op\Expr\BinaryOp\Equal
            || $expr instanceof Op\Expr\BinaryOp\NotEqual
            || $expr instanceof Op\Expr\BinaryOp\Spaceship
            || $expr instanceof Op\Expr\BinaryOp\Smaller
            || $expr instanceof Op\Expr\BinaryOp\Greater
            || $expr instanceof Op\Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Op\Expr\BinaryOp\GreaterOrEqual
            || $expr instanceof Op\Expr\InstanceOf_
            || $expr instanceof Op\Expr\In_;
    }

    /**
     * php-cfg dead temps for inline arithmetic/bitwise before a consumer call arg (#17210, #17562).
     */
    private function isArithmeticInlineCallArgProducer(mixed $expr): bool
    {
        return $expr instanceof Op\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Op\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Op\Expr\BinaryOp\BitwiseXor
            || $expr instanceof Op\Expr\BinaryOp\Plus
            || $expr instanceof Op\Expr\BinaryOp\Minus
            || $expr instanceof Op\Expr\BinaryOp\Mul
            || $expr instanceof Op\Expr\BinaryOp\Div
            || $expr instanceof Op\Expr\BinaryOp\Mod
            || $expr instanceof Op\Expr\BinaryOp\Pow
            || $expr instanceof Op\Expr\BinaryOp\ShiftLeft
            || $expr instanceof Op\Expr\BinaryOp\ShiftRight;
    }

    /**
     * php-cfg dead temps for `var_export($o->p)` / `var_export($a[0])` / `var_export($o->x++)`
     * — immediate prelude feeds arg #0 (#17540, #26491 / re-#10123).
     *
     * Do not list ConcatList / BinaryOp\Concat here: resolvePrecedingExpressionPreludeCallArgSlot
     * and sibling producers compile via compileExpr(), which does not lower ConcatList (#26489).
     * Encapsed skip lives in rewireVarExportNestedInlineCallArgSendSlots instead.
     */
    private function isImmediateVarExportExpressionPrelude(mixed $expr): bool
    {
        if (!$expr instanceof Op\Expr) {
            return false;
        }

        return $expr instanceof Op\Expr\PropertyFetch
            || $expr instanceof Op\Expr\NullsafePropertyFetch
            || $expr instanceof Op\Expr\StaticPropertyFetch
            || $expr instanceof Op\Expr\ArrayDimFetch
            || $expr instanceof Op\Expr\Cast
            || $expr instanceof Op\Expr\BooleanNot
            || $expr instanceof Op\Expr\BitwiseNot
            || $expr instanceof Op\Expr\UnaryMinus
            || $expr instanceof Op\Expr\UnaryPlus
            || $expr instanceof Op\Expr\PostInc
            || $expr instanceof Op\Expr\PreInc
            || $expr instanceof Op\Expr\PostDec
            || $expr instanceof Op\Expr\PreDec
            || $expr instanceof Op\Expr\Include_
            || $expr instanceof Op\Expr\Eval_
            || $this->isComparisonInlineCallArgProducer($expr)
            || $this->isArithmeticInlineCallArgProducer($expr);
    }

    /**
     * php-cfg hoists Array_/FuncCall operands before a sibling compare feeding var_export arg (#17277).
     *
     * @param list<Op> $cfgChildren
     */
    private function hoistedExprFeedsSiblingComparisonBeforeCall(
        Op\Expr $expr,
        int $exprIndex,
        int $callIndex,
        array $cfgChildren
    ): bool {
        for ($j = $exprIndex + 1; $j < $callIndex; ++$j) {
            $scan = $cfgChildren[$j] ?? null;
            if (
                $this->isComparisonInlineCallArgProducer($scan)
                && null !== $expr->result
                && $this->cfgExprUsesOperand($scan, $expr->result)
            ) {
                return true;
            }
            if ($scan instanceof Op\Expr\ConstFetch || $scan instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * php-cfg dead ClassConstFetch preludes before inline Array_/Concat call args (#5933, #4109).
     *
     * @param list<Op\Expr> $producers
     *
     * @return list<Op\Expr>
     */
    private function filterDeadClassConstFetchInlineProducers(array $producers): array
    {
        if (count($producers) < 2) {
            return $producers;
        }
        $filtered = [];
        foreach ($producers as $i => $producer) {
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $feedsLater = false;
                for ($j = $i + 1, $n = count($producers); $j < $n; ++$j) {
                    if ($this->cfgExprUsesOperand($producers[$j], $producer->result)) {
                        $feedsLater = true;
                        break;
                    }
                }
                if ($feedsLater) {
                    continue;
                }
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }
}
