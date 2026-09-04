<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\ErrorSuppressBlock;

/**
 * Error-suppress (@) end-block call-arg producers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers post-END_SILENCE forwarded inner-result args and trailing
 * bitmask/concat/closure/include/new/comparison/array/scalar producers
 * used from compileCallArgSends / inheritErrorSuppressExpressionSlots.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types;
 * call-arg slot wiring relies on coercion (same as CompileCallArgSends).
 */
trait ErrorSuppressCallArgProducers
{
    /**
     * True when a call arg reads the `@`-suppressed inner expression in the post-END_SILENCE block (#15916).
     */
    private function callArgIsErrorSuppressForwardedResult(Operand $callArg, Block $block): bool
    {
        $endCfg = $block->orig;
        if (null === $endCfg || 1 !== \count($endCfg->parents)) {
            return false;
        }
        $parent = $endCfg->parents[0];
        if (!$parent instanceof ErrorSuppressBlock) {
            return false;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parent);
        if (null === $primary || !isset($primary->result)) {
            return false;
        }

        return $this->operandsReferToSameVariable($callArg, $primary->result);
    }

    /** FUNCCALL_EXEC_RETURN / TYPE_INCLUDE slot from the {@see ErrorSuppressBlock} parent (#15916, #21938). */
    private function errorSuppressEndBlockInnerResultSlot(Block $block): ?int
    {
        if ($this->errorSuppressEndBlockDiscardsInnerResultForErrorGetLast($block)) {
            return null;
        }
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return null;
        }
        $parentCfg = $endCfg->parents[0];
        if (!$parentCfg instanceof ErrorSuppressBlock || !$this->seen->contains($parentCfg)) {
            return null;
        }
        $parentCompiled = $this->seen[$parentCfg];
        if (!$parentCompiled instanceof Block) {
            return null;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null !== $primary && isset($primary->result)) {
            $bound = $parentCompiled->slotForOperand($primary->result);
            if (null !== $bound) {
                return (int) $bound;
            }
        }
        $execReturn = $this->findFuncCallExecReturnSlot($parentCompiled);
        if (null !== $execReturn) {
            return $execReturn;
        }

        return $this->findIncludeReturnSlot($parentCompiled);
    }

    private function isFirstNonEmbeddedDeadInlineCallArg(Op $cfgCallOp, int $argIndex): bool
    {
        if (!property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$candidate instanceof Operand || $this->isEmbeddedCallLiteralArg($candidate)) {
                continue;
            }
            if (!$this->callArgIsDeadInlineTemporary($candidate)) {
                continue;
            }

            return (int) $i === $argIndex;
        }

        return false;
    }

    private function errorSuppressEndBlockInnerResultSlotForCallArg(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?int {
        if (null === $cfgCallOp || !$this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex)) {
            return null;
        }
        $callArg = (property_exists($cfgCallOp, 'args') && \is_array($cfgCallOp->args))
            ? ($cfgCallOp->args[$argIndex] ?? null)
            : null;
        if ($this->callArgOpsContainInlineClosure($callArg)) {
            return null;
        }
        if ($this->errorSuppressEndBlockDiscardsInnerResultForErrorGetLast($block)) {
            return null;
        }
        // `@mkdir(...); new Outer(new Inner(...))` — dead arg temp is the inner New_, not @mkdir (#24368).
        if ($this->callArgInlineProducerIsNew($cfgCallOp, $argIndex, $block)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasAdjacentNestedNewProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        // `@mkdir(...); var_export(require $f)` — include in the end block feeds the call, not @mkdir (#21938).
        if ($this->errorSuppressEndBlockCallArgHasTrailingIncludeProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingHoistedScalarProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingHoistedArrayProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingArrayDimFetchProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasAdjacentNestedFuncCallProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingComparisonProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingConcatProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingClosureProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }
        if ($this->errorSuppressEndBlockCallArgHasTrailingBitmaskProducer($block, $cfgCallOp, $argIndex)) {
            return null;
        }

        return $this->errorSuppressEndBlockInnerResultSlot($block);
    }

    /**
     * Trailing inline bitmask / scalar option prelude before a post-@ call/ctor feeds this
     * dead-temp arg — not the @ return (#24369, #18523 family).
     *
     * Example: `@mkdir($dir); new FilesystemIterator($dir, CURRENT_AS_PATHNAME | SKIP_DOTS)`.
     * Only the trailing non-embedded arg binds the prelude so `@stat; foo($stat, F|F)` still
     * forwards the suppress result on arg #0.
     */
    private function errorSuppressEndBlockCallArgHasTrailingBitmaskProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        if ((int) $argIndex !== $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if (
                $prev instanceof Op\Expr\ConstFetch
                || $prev instanceof Op\Expr\ClassConstFetch
            ) {
                continue;
            }
            if ($prev instanceof Op\Expr\Assign) {
                $prev = $prev->expr;
            }
            if (
                $this->isArithmeticInlineCallArgProducer($prev)
                || $prev instanceof Op\Expr\UnaryMinus
                || $prev instanceof Op\Expr\UnaryPlus
                || $prev instanceof Op\Expr\BitwiseNot
                || $prev instanceof Op\Expr\Cast
            ) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        // php-cfg allocates a distinct dead arg temp from the BitwiseOr/Plus result (#18523, #24369).
        return true;
    }

    /**
     * `var_export("$d/y")` / `printf("%s", $d."/y")` after `@strlen($d)` —
     * ConcatList / BinaryOp\Concat feeds the dead-temp arg, not the @ return (#23045).
     */
    private function errorSuppressEndBlockCallArgHasTrailingConcatProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if (
                $prev instanceof Op\Expr\ConstFetch
                || $prev instanceof Op\Expr\ClassConstFetch
                || $prev instanceof Op\Expr\UnaryMinus
                || $prev instanceof Op\Expr\UnaryPlus
            ) {
                continue;
            }
            if ($prev instanceof Op\Expr\ConcatList || $prev instanceof Op\Expr\BinaryOp\Concat) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        // php-cfg allocates a distinct dead arg temp from ConcatList/Concat.result (#13466, #23045).
        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * `@strlen(null); set_error_handler(function...)` — Closure in the end block feeds
     * the dead-temp arg, not the @ return (#23730).
     */
    private function errorSuppressEndBlockCallArgHasTrailingClosureProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\Closure || $prev instanceof Op\Expr\ArrowFunction) {
                return true;
            }
            if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\Assign) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * `var_export(require $f)` / `var_export(include $f, true)` after `@mkdir` — Include_/Eval_
     * in the post-silence block feeds arg #0, not the @ return (#21938, #25851).
     *
     * Two-arg form hoists `true`/`false` ConstFetch immediately before the call; skip those so
     * Include_ is still seen (single-arg already matched `$callIndex - 1`).
     */
    private function errorSuppressEndBlockCallArgHasTrailingIncludeProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = null;
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($this->isHoistedScalarConstFetchImmediatelyBeforeCall($prev)) {
                continue;
            }
            if ($prev instanceof Op\Expr\Include_ || $prev instanceof Op\Expr\Eval_) {
                $producer = $prev;
            }
            break;
        }
        if (null === $producer) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * `@f(); g(); var_export(h(), true)` — adjacent hoisted callee feeds dead-temp arg, not @ return (#8974).
     * Also `trim($d->saveHTML())` after `@$d->loadHTML(...)` — MethodCall/StaticCall producer (#22345).
     */
    private function errorSuppressEndBlockCallArgHasAdjacentNestedFuncCallProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producerIndex = $callIndex - 1;
        $producer = $block->orig->children[$producerIndex] ?? null;
        if (
            !(
                $producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\StaticCall
            )
            || !$this->isNestedCallArgProducerForConsumer(
                $producer,
                $cfgCallOp,
                $producerIndex,
                $callIndex,
                $block->orig->children
            )
        ) {
            return false;
        }
        $targetArgIndex = $this->siblingMultiArgFuncCallProducerTargetArgIndex(
            $producerIndex,
            $callIndex,
            $block->orig->children
        );
        if (null === $targetArgIndex) {
            $targetArgIndex = 0;
        }

        return $argIndex === $targetArgIndex;
    }

    /**
     * `@mkdir($dir); new Outer(new Inner($dir))` — adjacent New_ feeds the dead-temp arg (#24368).
     *
     * php-cfg may rewrite New_->result into a distinct Temporary on the outer arg; link via
     * `$arg->ops` / {@see inlineNewProducerFeedsCallArg} (same family as #19439 / #12916).
     */
    private function errorSuppressEndBlockCallArgHasAdjacentNestedNewProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg) && !$this->callArgIsNewExpression($callArg)) {
            return false;
        }
        if (
            $callArg instanceof Operand
            && isset($callArg->ops)
            && \is_array($callArg->ops)
        ) {
            foreach ($callArg->ops as $writeOp) {
                if ($writeOp instanceof Op\Expr\New_) {
                    return true;
                }
            }
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$prev instanceof Op\Expr\New_) {
                break;
            }
            if ($this->inlineNewProducerFeedsCallArg($prev, $callArg)) {
                return true;
            }
            // Nested ctor chain: inner New_ immediately precedes outer New_/call (#24368, #12916).
            if ((int) $argIndex === 0 && $i === $callIndex - 1) {
                return true;
            }
            break;
        }

        return false;
    }

    /**
     * `var_dump($h !== false)` after `@fopen` — hoisted compare feeds dead-temp arg, not @ return (#18185, #13694).
     */
    private function errorSuppressEndBlockCallArgHasTrailingComparisonProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->isComparisonInlineCallArgProducer($prev)) {
                break;
            }
            if (
                null !== $prev->result
                && (
                    $this->operandsReferToSameVariable($prev->result, $callArg)
                    || $this->callArgIsDeadInlineTemporary($callArg)
                )
            ) {
                return true;
            }
            break;
        }

        return false;
    }

    /**
     * `$v = @f(); g($v)` in END_SILENCE — reads must use the assign CV lvalue, not assign.result temp (#16262).
     */
    private function slotForPostErrorSuppressAssignNamedLocalCallArg(Operand $arg, Block $block): ?int
    {
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return null;
        }
        $parentCfg = $endCfg->parents[0] ?? null;
        if (!$parentCfg instanceof ErrorSuppressBlock) {
            return null;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null === $primary || !isset($primary->result)) {
            return null;
        }
        foreach ($endCfg->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->expr, $primary->result)) {
                continue;
            }
            if (!$this->operandsReferToSameVariable($child->var, $arg)) {
                continue;
            }
            $namedDest = $block->slotForNamedAssignDest($child->var);
            if (null !== $namedDest) {
                return (int) $namedDest;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN === $op->type && (int) $op->arg2 === $block->getVarSlot($child->var, false)) {
                    return (int) $op->arg2;
                }
            }
        }

        return null;
    }

    /**
     * Trailing hoisted Array_ before a post-@ call feeds this dead-temp arg (#16205).
     */
    private function errorSuppressEndBlockCallArgHasTrailingHoistedArrayProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $arrayProducer = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
        if (!$arrayProducer instanceof Op\Expr\Array_) {
            return false;
        }
        if (
            null !== $arrayProducer->result
            && $this->operandsReferToSameVariable($arrayProducer->result, $callArg)
        ) {
            return true;
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return false;
        }

        return 0 === $producerOrdinal;
    }

    /**
     * Trailing ArrayDimFetch before a post-@ call feeds this dead-temp arg (#18005).
     */
    private function errorSuppressEndBlockCallArgHasTrailingArrayDimFetchProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = array_search($cfgCallOp, $children, true);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return false;
        }
        $producer = $children[$callIndex - 1] ?? null;
        if (!$producer instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if (
            null !== $producer->result
            && $this->operandsReferToSameVariable($producer->result, $callArg)
        ) {
            return true;
        }

        return $this->isFirstNonEmbeddedDeadInlineCallArg($cfgCallOp, $argIndex);
    }

    /**
     * Trailing hoisted true/false/null before a post-@ call feeds this dead-temp arg (#15916).
     *
     * When hoisted scalars only cover later args, arg #0 remains the suppress forward (#10302).
     */
    private function errorSuppressEndBlockCallArgHasTrailingHoistedScalarProducer(
        Block $block,
        Op $cfgCallOp,
        int $argIndex
    ): bool {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        $callArg = $cfgCallOp->args[$argIndex] ?? null;
        if (!$callArg instanceof Operand || $this->isEmbeddedCallLiteralArg($callArg)) {
            return false;
        }
        if (!$this->callArgIsDeadInlineTemporary($callArg)) {
            return false;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $cfgCallOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return false;
        }
        $trailingConstFetches = [];
        for ($i = $callIndex - 1; $i >= 0 && $callIndex - $i <= 8; --$i) {
            $prev = $children[$i] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);
                if (null !== $name && \in_array(strtolower($name), ['true', 'false', 'null'], true)) {
                    array_unshift($trailingConstFetches, $prev);
                    continue;
                }
                break;
            }
            if ($prev instanceof Op\Expr\Assign) {
                continue;
            }
            if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                return false;
            }
            break;
        }
        if ([] === $trailingConstFetches) {
            return false;
        }
        foreach ($trailingConstFetches as $fetch) {
            if (null !== $fetch->result && $this->operandsReferToSameVariable($fetch->result, $callArg)) {
                return true;
            }
        }
        $nonEmbeddedArgIndices = [];
        foreach ($cfgCallOp->args as $i => $candidate) {
            if (!$this->isEmbeddedCallLiteralArg($candidate)) {
                $nonEmbeddedArgIndices[] = (int) $i;
            }
        }
        $producerOrdinal = array_search($argIndex, $nonEmbeddedArgIndices, true);
        if (false === $producerOrdinal) {
            return false;
        }
        if (
            0 === $producerOrdinal
            && \count($nonEmbeddedArgIndices) > 1
            && \count($trailingConstFetches) < \count($nonEmbeddedArgIndices)
        ) {
            return false;
        }

        return isset($trailingConstFetches[$producerOrdinal]);
    }

    /**
     * True when an outer call in a post-@ block consumes the suppressed inner expression (#10336, #15916).
     *
     * Standalone `@mkdir(); stream_context_create(null|[])` must not wire hoisted literal to mkdir's return slot.
     */
    private function callInErrorSuppressEndBlockUsesInnerResultAsArg(Block $block, Op $cfgCallOp): bool
    {
        $endCfg = $block->orig;
        if (null === $endCfg || !$this->isErrorSuppressEndBlock($endCfg)) {
            return false;
        }
        $parentCfg = $endCfg->parents[0];
        if (!$parentCfg instanceof ErrorSuppressBlock) {
            return false;
        }
        $primary = $this->findErrorSuppressPrimaryInnerExpr($parentCfg);
        if (null === $primary || !isset($primary->result)) {
            return false;
        }
        if (!property_exists($cfgCallOp, 'args') || !\is_array($cfgCallOp->args)) {
            return false;
        }
        foreach ($primary->result->usages as $usage) {
            if ($usage === $cfgCallOp) {
                return true;
            }
        }
        foreach ($cfgCallOp->args as $arg) {
            if ($arg instanceof Operand && $this->operandsReferToSameVariable($arg, $primary->result)) {
                return true;
            }
        }

        return false;
    }
}
