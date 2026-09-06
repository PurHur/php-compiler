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
 * Covers {@see slotForImmediatePropertyOrMethodFetchBeforeCfgCall} through
 * named-assign helpers. INIT_ARRAY / array-spread / inline-arithmetic /
 * nested-subject peers live in
 * {@see InitArraySpreadArithmeticAndNestedInlineCallArgResolvers}.
 * Closure / first-class-callable peers live in
 * {@see SlotForInlineClosureAndFirstClassCallableCallArgResolvers}.
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

}
