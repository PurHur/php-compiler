<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Dim/property fetch write-context detection + by-ref write helpers (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers {@see isArrayDimFetchForWrite}, {@see isPropertyFetchForWrite}, and the
 * nested-write / AssignOp / by-ref call-arg / yield-return helpers they share.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; write-context
 * operand matching relies on coercion (same as ListDestructAndForeach).
 */
trait DimAndPropertyWriteContext
{
    /**
     * True when the fetch result is only used as a write lvalue (assign, unset, or ++/--; issue #103, #1224, #6798).
     * Nested write through a dimension ($obj[$k][] = $v) also requires write fetch on the outer dim (#3446).
     */
    protected function isArrayDimFetchForWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): bool
    {
        // Nested list by-ref: `[[$x,&$y]]=$a` uses $a[0] as container for both a read slot
        // and a FETCH_DIM_W/AssignRef slot. A read sibling in usages must not disqualify the
        // write sibling (#34778 / leftover #34673, zend_execute.c list unpack).
        if ($this->arrayDimFetchHasNestedWriteUsage($fetch, $block)) {
            return true;
        }
        foreach ($fetch->result->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign && $usage->var === $fetch->result) {
                continue;
            }
            // AssignRef RHS needs FETCH_DIM_W for reference acquisition (#7441, zend_execute.c).
            if (
                $usage instanceof Op\Expr\AssignRef
                && ($usage->var === $fetch->result || $usage->expr === $fetch->result)
            ) {
                continue;
            }
            // `yield $arr[$i]` from `function &gen()` needs the live element (#25877).
            if ($this->arrayDimFetchUsedAsByRefYieldValue($fetch, $usage, $block)) {
                continue;
            }
            // `return $arr[$i]` / `return $GLOBALS['x']` from `function &f()` (#34733 / re-#34717).
            if ($this->arrayDimFetchUsedAsByRefReturnValue($fetch, $usage, $block)) {
                continue;
            }
            // `[&$s[$i]]` — by-ref array element must FETCH_DIM_W so string offsets raise (#21910).
            if (
                $usage instanceof Op\Expr\Array_
                && $this->arrayLiteralHasByRefElementOperand($usage, $fetch->result)
            ) {
                continue;
            }
            if ($usage instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($usage, $fetch->result)) {
                continue;
            }
            if ($this->isIncDecUsingOperand($usage, $fetch->result)) {
                continue;
            }
            // AssignOp ($s[$i] += 1) expands to BinaryOp(left=fetch) + Assign(var=fetch) (#22897).
            if ($this->isAssignOpBinaryUsingDimFetch($usage, $fetch, $block)) {
                continue;
            }
            if (
                $usage instanceof Op\Expr\ArrayDimFetch
                && $usage->var === $fetch->result
                && $this->isArrayDimFetchForWrite($usage, $block)
            ) {
                return true;
            }
            if ($this->arrayDimFetchUsedAsByRefCallArg($fetch, $usage, $block)) {
                continue;
            }

            return false;
        }
        if (!empty($fetch->result->usages)) {
            return true;
        }
        // php-cfg often leaves operand->usages empty; fall back to the next stmt in this block.
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if ($child !== $fetch) {
                continue;
            }
            if ($i + 1 >= count($children)) {
                break;
            }
            $next = $children[$i + 1];

            if ($next instanceof Op\Expr\Assign && $next->var === $fetch->result) {
                return true;
            }
            if (
                $next instanceof Op\Expr\AssignRef
                && ($next->var === $fetch->result || $next->expr === $fetch->result)
            ) {
                return true;
            }
            // php-cfg: ArrayDimFetch then Yield from function &gen() (#25877).
            if ($this->arrayDimFetchUsedAsByRefYieldValue($fetch, $next, $block)) {
                return true;
            }
            // php-cfg: ArrayDimFetch then Return from function &f() (#34733).
            if ($this->arrayDimFetchUsedAsByRefReturnValue($fetch, $next, $block)) {
                return true;
            }
            // php-cfg: ArrayDimFetch then Expr_Array with byRef element (#21910).
            if (
                $next instanceof Op\Expr\Array_
                && $this->arrayLiteralHasByRefElementOperand($next, $fetch->result)
            ) {
                return true;
            }
            if ($next instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($next, $fetch->result)) {
                return true;
            }
            if ($this->isIncDecUsingOperand($next, $fetch->result)) {
                return true;
            }
            // AssignOp: ArrayDimFetch; BinaryOp; Assign back to fetch (#22897).
            if (
                $next instanceof Op\Expr\BinaryOp
                && $this->isAssignOpPatternFollowingDimFetch($fetch, $next, $children, $i)
            ) {
                return true;
            }
            if (
                $next instanceof Op\Expr\ArrayDimFetch
                && $next->var === $fetch->result
                && $this->isArrayDimFetchForWrite($next, $block)
            ) {
                return true;
            }
            // php-cfg may emit read list slots before the by-ref write slot (#34778).
            if ($this->arrayDimFetchHasNestedWriteAmongChildren($fetch, $children, $i + 1, $block)) {
                return true;
            }
            if ($this->arrayDimFetchPrecedesByRefBuiltinCall($fetch, $next, $block, $i, $children)) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * True when any usage of $fetch->result is a nested ArrayDimFetch that itself needs write.
     *
     * @see #34778 nested list by-ref outer container
     */
    private function arrayDimFetchHasNestedWriteUsage(Op\Expr\ArrayDimFetch $fetch, Block $block): bool
    {
        foreach ($fetch->result->usages as $usage) {
            if (
                $usage instanceof Op\Expr\ArrayDimFetch
                && $usage->var === $fetch->result
                && $this->isArrayDimFetchForWrite($usage, $block)
            ) {
                return true;
            }
        }
        if (null === $block->orig) {
            return false;
        }

        return $this->arrayDimFetchHasNestedWriteAmongChildren(
            $fetch,
            $block->orig->children,
            0,
            $block
        );
    }

    /**
     * Scan CFG children from $fromIndex for nested write dims on $fetch->result (#34778).
     *
     * @param Op[] $children
     */
    private function arrayDimFetchHasNestedWriteAmongChildren(
        Op\Expr\ArrayDimFetch $fetch,
        array $children,
        int $fromIndex,
        Block $block
    ): bool {
        $count = count($children);
        for ($j = $fromIndex; $j < $count; ++$j) {
            $cand = $children[$j];
            if (
                $cand instanceof Op\Expr\ArrayDimFetch
                && $cand->var === $fetch->result
                && $cand !== $fetch
                && $this->isArrayDimFetchForWrite($cand, $block)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-cfg expands AssignOp to BinaryOp(left=dim) + Assign(var=dim); ??= stays read (#22897).
     *
     * The trailing Assign often is absent from operand->usages (only BinaryOp is listed).
     */
    private function isAssignOpBinaryUsingDimFetch($usage, Op\Expr\ArrayDimFetch $fetch, Block $block): bool
    {
        if (!$usage instanceof Op\Expr\BinaryOp) {
            return false;
        }
        // Zend allows ??= on string offsets (no assign-op Error).
        if ($usage instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        if ($usage->left !== $fetch->result) {
            return false;
        }
        foreach ($fetch->result->usages as $u) {
            if (
                $u instanceof Op\Expr\Assign
                && $u->var === $fetch->result
                && $u->expr === $usage->result
            ) {
                return true;
            }
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Assign
                && $child->var === $fetch->result
                && $child->expr === $usage->result
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Op> $children
     */
    private function isAssignOpPatternFollowingDimFetch(
        Op\Expr\ArrayDimFetch $fetch,
        Op\Expr\BinaryOp $bin,
        array $children,
        int $fetchIndex
    ): bool {
        if ($bin instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        if ($bin->left !== $fetch->result) {
            return false;
        }
        if ($fetchIndex + 2 >= \count($children)) {
            return false;
        }
        $assign = $children[$fetchIndex + 2];

        return $assign instanceof Op\Expr\Assign
            && $assign->var === $fetch->result
            && $assign->expr === $bin->result;
    }

    /**
     * php-cfg expands AssignOp to BinaryOp(left=prop) + Assign(var=prop); ??= stays read (#35978).
     *
     * The trailing Assign often is absent from operand->usages (only BinaryOp is listed).
     */
    private function isAssignOpBinaryUsingPropertyFetch($usage, Op\Expr\PropertyFetch $fetch, Block $block): bool
    {
        if (!$usage instanceof Op\Expr\BinaryOp) {
            return false;
        }
        if ($usage instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        if ($usage->left !== $fetch->result) {
            return false;
        }
        foreach ($fetch->result->usages as $u) {
            if (
                $u instanceof Op\Expr\Assign
                && $u->var === $fetch->result
                && $u->expr === $usage->result
            ) {
                return true;
            }
        }
        if (null === $block->orig) {
            return false;
        }
        foreach ($block->orig->children as $child) {
            if (
                $child instanceof Op\Expr\Assign
                && $child->var === $fetch->result
                && $child->expr === $usage->result
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Op> $children
     */
    private function isAssignOpPatternFollowingPropertyFetch(
        Op\Expr\PropertyFetch $fetch,
        Op\Expr\BinaryOp $bin,
        array $children,
        int $fetchIndex
    ): bool {
        if ($bin instanceof Op\Expr\BinaryOp\Coalesce) {
            return false;
        }
        if ($bin->left !== $fetch->result) {
            return false;
        }
        if ($fetchIndex + 2 >= \count($children)) {
            return false;
        }
        $assign = $children[$fetchIndex + 2];

        return $assign instanceof Op\Expr\Assign
            && $assign->var === $fetch->result
            && $assign->expr === $bin->result;
    }

    /**
     * sscanf('%d', $a[0]) — php-cfg dead arg temps; dim fetch immediately precedes call (#4512).
     */
    private function arrayDimFetchPrecedesByRefBuiltinCall(
        Op\Expr\ArrayDimFetch $fetch,
        Op $maybeCall,
        Block $block,
        int $fetchChildIndex,
        array $children
    ): bool {
        if (!$maybeCall instanceof Op\Expr\FuncCall && !$maybeCall instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $callIndex = $fetchChildIndex + 1;
        if ($callIndex >= \count($children) || $children[$callIndex] !== $maybeCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($maybeCall);
        if (null === $calleeName) {
            return false;
        }
        /** @var list<Op\Expr\ArrayDimFetch> $dimFetches */
        $dimFetches = [];
        for ($j = $fetchChildIndex; $j >= 0; --$j) {
            $child = $children[$j];
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                array_unshift($dimFetches, $child);
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }
        if ([] === $dimFetches) {
            return false;
        }
        $dimFetchIndex = array_search($fetch, $dimFetches, true);
        if (false === $dimFetchIndex) {
            return false;
        }
        $callArgs = property_exists($maybeCall, 'args') && is_array($maybeCall->args)
            ? $maybeCall->args
            : [];
        $argIndex = (int) $dimFetchIndex;
        if (\count($dimFetches) < \count($callArgs)) {
            $nonEmbeddedArgIndices = [];
            foreach ($callArgs as $idx => $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    $nonEmbeddedArgIndices[] = $idx;
                }
            }
            if (!isset($nonEmbeddedArgIndices[$dimFetchIndex])) {
                return false;
            }
            $argIndex = (int) $nonEmbeddedArgIndices[$dimFetchIndex];
        }

        return $this->callArgRequiresByRef($calleeName, $argIndex, null, $block);
    }

    /**
     * yield $arr[$i] from function &gen() — FETCH_DIM_W so foreach as &$v writeback hits the element (#25877).
     */
    private function arrayDimFetchUsedAsByRefYieldValue(
        Op\Expr\ArrayDimFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Expr\Yield_) {
            return false;
        }
        if ($usage->value !== $fetch->result) {
            return false;
        }

        return $this->cfgFunctionReturnsByReference($block);
    }

    /**
     * `return $arr[$i]` / `return $GLOBALS['x']` from `function &name()` needs FETCH_DIM_W
     * (#34733 / re-#34717, zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF).
     */
    private function arrayDimFetchUsedAsByRefReturnValue(
        Op\Expr\ArrayDimFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Terminal\Return_) {
            return false;
        }
        if ($usage->expr !== $fetch->result) {
            return false;
        }

        return $this->cfgFunctionReturnsByReference($block);
    }

    /**
     * `return $this->prop` from `function &name()` needs FETCH_OBJ_W (#29456, zend_execute.c).
     */
    private function propertyFetchUsedAsByRefReturnValue(
        Op\Expr\PropertyFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Terminal\Return_) {
            return false;
        }
        if ($usage->expr !== $fetch->result) {
            return false;
        }

        return $this->cfgFunctionReturnsByReference($block);
    }

    /**
     * `yield $this->prop` from `function &gen()` needs the live property cell (#29456).
     */
    private function propertyFetchUsedAsByRefYieldValue(
        Op\Expr\PropertyFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Expr\Yield_) {
            return false;
        }
        if ($usage->value !== $fetch->result) {
            return false;
        }

        return $this->cfgFunctionReturnsByReference($block);
    }

    /** True when the enclosing CFG func is declared `function &name()` (FLAG_RETURNS_REF). */
    private function cfgFunctionReturnsByReference(Block $block): bool
    {
        $decl = $block->func ?? null;

        return null !== $decl
            && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** @deprecated alias — FLAG_RETURNS_REF covers yield-by-ref and return-by-ref */
    private function cfgFunctionYieldsByReference(Block $block): bool
    {
        return $this->cfgFunctionReturnsByReference($block);
    }

    /**
     * sscanf('%d', $a[0]) — by-ref builtin args need FETCH_DIM_W (#4512, zend_execute.c ZEND_SEND_REF).
     */
    private function arrayDimFetchUsedAsByRefCallArg(
        Op\Expr\ArrayDimFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Expr\FuncCall && !$usage instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($usage, 'args') || !is_array($usage->args)) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($usage);
        if (null === $calleeName) {
            return false;
        }
        foreach ($usage->args as $argIndex => $callArg) {
            if (
                null === $callArg
                || (
                    $callArg !== $fetch->result
                    && !$this->operandsReferToSameVariable($callArg, $fetch->result)
                )
            ) {
                continue;
            }
            if ($this->callArgRequiresByRef($calleeName, (int) $argIndex, $callArg, $block)) {
                return true;
            }
        }

        return false;
    }

    /**
     * bump($obj->prop) — by-ref call args need FETCH_OBJ_W (#25301, zend_execute.c ZEND_SEND_REF).
     */
    private function propertyFetchUsedAsByRefCallArg(
        Op\Expr\PropertyFetch $fetch,
        Op $usage,
        Block $block
    ): bool {
        if (!$usage instanceof Op\Expr\FuncCall && !$usage instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        if (!property_exists($usage, 'args') || !is_array($usage->args)) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($usage);
        if (null === $calleeName) {
            return false;
        }
        $argIndex = $this->propertyFetchByRefCallArgIndex($fetch, $usage, $block);
        if (null === $argIndex) {
            return false;
        }

        return $this->callArgRequiresByRef($calleeName, $argIndex, null, $block);
    }

    /**
     * @return ?int call arg index when $fetch is a by-ref actual (operand temps may differ, #25301).
     */
    private function propertyFetchByRefCallArgIndex(
        Op\Expr\PropertyFetch $fetch,
        Op\Expr\FuncCall|Op\Expr\NsFuncCall $call,
        ?Block $block = null,
        ?array $children = null
    ): ?int {
        if (!property_exists($call, 'args') || !is_array($call->args)) {
            return null;
        }
        foreach ($call->args as $argIndex => $callArg) {
            if (
                null !== $callArg
                && (
                    $callArg === $fetch->result
                    || $this->operandsReferToSameVariable($callArg, $fetch->result)
                )
            ) {
                return (int) $argIndex;
            }
        }
        if (null === $children && null !== $block?->orig) {
            $children = $block->orig->children;
        }
        if (null === $children) {
            return null;
        }
        $fetchIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $fetch) {
                $fetchIndex = $i;
                break;
            }
        }
        if (null === $fetchIndex) {
            return null;
        }
        /** @var list<Op\Expr\PropertyFetch> $propFetches */
        $propFetches = [];
        for ($j = $fetchIndex; $j >= 0; --$j) {
            $child = $children[$j];
            if ($child instanceof Op\Expr\PropertyFetch) {
                array_unshift($propFetches, $child);
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            break;
        }
        if ([] === $propFetches) {
            return null;
        }
        $propFetchIndex = array_search($fetch, $propFetches, true);
        if (false === $propFetchIndex) {
            return null;
        }
        $callIndex = $fetchIndex + 1;
        if ($callIndex >= \count($children) || $children[$callIndex] !== $call) {
            return null;
        }
        $callArgs = $call->args;
        $argIndex = (int) $propFetchIndex;
        if (\count($propFetches) < \count($callArgs)) {
            $nonEmbeddedArgIndices = [];
            foreach ($callArgs as $idx => $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    $nonEmbeddedArgIndices[] = $idx;
                }
            }
            if (!isset($nonEmbeddedArgIndices[$propFetchIndex])) {
                return null;
            }
            $argIndex = (int) $nonEmbeddedArgIndices[$propFetchIndex];
        }

        return $argIndex;
    }

    /**
     * php-cfg: PropertyFetch immediately precedes by-ref FuncCall when usages are empty (#25301).
     *
     * @param list<Op> $children
     */
    private function propertyFetchPrecedesByRefCall(
        Op\Expr\PropertyFetch $fetch,
        Op $maybeCall,
        Block $block,
        int $fetchChildIndex,
        array $children
    ): bool {
        if (!$maybeCall instanceof Op\Expr\FuncCall && !$maybeCall instanceof Op\Expr\NsFuncCall) {
            return false;
        }
        $callIndex = $fetchChildIndex + 1;
        if ($callIndex >= \count($children) || $children[$callIndex] !== $maybeCall) {
            return false;
        }
        $calleeName = $this->funcCallExprCalleeName($maybeCall);
        if (null === $calleeName) {
            return false;
        }
        $argIndex = $this->propertyFetchByRefCallArgIndex($fetch, $maybeCall, $block, $children);
        if (null === $argIndex) {
            return false;
        }

        return $this->callArgRequiresByRef($calleeName, $argIndex, null, $block);
    }

    /**
     * True when property fetch is only used as write/ref lvalue (assign, AssignRef, unset, ++/--; #13559).
     */
    protected function isPropertyFetchForWrite(Op\Expr\PropertyFetch $fetch, Block $block): bool
    {
        if ($this->forcePropertyFetchForWrite > 0) {
            return true;
        }
        foreach ($fetch->result->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign && $usage->var === $fetch->result) {
                continue;
            }
            if (
                $usage instanceof Op\Expr\AssignRef
                && ($usage->var === $fetch->result || $usage->expr === $fetch->result)
            ) {
                continue;
            }
            if ($usage instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($usage, $fetch->result)) {
                continue;
            }
            if ($this->isIncDecUsingOperand($usage, $fetch->result)) {
                continue;
            }
            if ($this->isAssignOpBinaryUsingPropertyFetch($usage, $fetch, $block)) {
                continue;
            }
            if ($this->propertyFetchUsedAsByRefCallArg($fetch, $usage, $block)) {
                continue;
            }
            // `return $this->prop` / `yield $this->prop` from `&method` (#29456).
            if ($this->propertyFetchUsedAsByRefReturnValue($fetch, $usage, $block)) {
                continue;
            }
            if ($this->propertyFetchUsedAsByRefYieldValue($fetch, $usage, $block)) {
                continue;
            }
            // $obj->prop[] = must read through get hook first; dim write uses FETCH not FETCH_W (#6775, #19171).
            if (
                $usage instanceof Op\Expr\ArrayDimFetch
                && $usage->var === $fetch->result
                && $this->isArrayDimFetchForWrite($usage, $block)
            ) {
                continue;
            }

            return false;
        }
        if (!empty($fetch->result->usages)) {
            if ($this->propertyFetchOnlyUsedAsDimWriteContainer($fetch, $block)) {
                return false;
            }

            return true;
        }
        $children = $block->orig->children;
        foreach ($children as $i => $child) {
            if ($child !== $fetch) {
                continue;
            }
            if ($i + 1 >= count($children)) {
                break;
            }
            $next = $children[$i + 1];

            if ($next instanceof Op\Expr\Assign && $next->var === $fetch->result) {
                return true;
            }
            if (
                $next instanceof Op\Expr\AssignRef
                && ($next->var === $fetch->result || $next->expr === $fetch->result)
            ) {
                return true;
            }
            if ($next instanceof Op\Terminal\Unset_ && $this->unsetTerminalUsesOperand($next, $fetch->result)) {
                return true;
            }
            if ($this->isIncDecUsingOperand($next, $fetch->result)) {
                return true;
            }
            if (
                $next instanceof Op\Expr\BinaryOp
                && $this->isAssignOpPatternFollowingPropertyFetch($fetch, $next, $children, $i)
            ) {
                return true;
            }
            if ($this->propertyFetchPrecedesByRefCall($fetch, $next, $block, $i, $children)) {
                return true;
            }
            if ($this->propertyFetchUsedAsByRefReturnValue($fetch, $next, $block)) {
                return true;
            }
            if ($this->propertyFetchUsedAsByRefYieldValue($fetch, $next, $block)) {
                return true;
            }
            // $obj->prop[] = — read fetch + dim write container (#6775, #19171).
            if (
                $next instanceof Op\Expr\ArrayDimFetch
                && $next->var === $fetch->result
                && $this->isArrayDimFetchForWrite($next, $block)
            ) {
                return false;
            }

            return false;
        }

        return false;
    }

    /** True when every usage of the property fetch is $obj->prop[…] = write (#6775, #19171). */
    private function propertyFetchOnlyUsedAsDimWriteContainer(Op\Expr\PropertyFetch $fetch, Block $block): bool
    {
        if ([] === $fetch->result->usages) {
            return false;
        }
        foreach ($fetch->result->usages as $usage) {
            if (
                $usage instanceof Op\Expr\ArrayDimFetch
                && $usage->var === $fetch->result
                && $this->isArrayDimFetchForWrite($usage, $block)
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param Op\Node $usage
     */
    private function isIncDecUsingOperand($usage, Operand $operand): bool
    {
        if (
            !$usage instanceof Op\Expr\PostInc
            && !$usage instanceof Op\Expr\PreInc
            && !$usage instanceof Op\Expr\PostDec
            && !$usage instanceof Op\Expr\PreDec
        ) {
            return false;
        }
        $write = $usage->write ?? $usage->read;

        return $usage->read === $operand || $write === $operand;
    }

}
