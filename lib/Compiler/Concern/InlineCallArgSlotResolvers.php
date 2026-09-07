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
 * Complements {@see SlotForCallArgResolvers}: match-result / void-prelude /
 * nested-New producer filters. Union/sibling/cast/comparison matchers live in
 * {@see InlineCallArgUnionSiblingCastAndComparisonMatchers}.
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
}
