<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\OpCode;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;

/**
 * Property/dim fetch compile helpers, call-arg property-fetch prelude wiring,
 * and `@` by-ref out-arg errno/errstr slot inheritance (#36387 / #36403).
 *
 * Extracted from {@see IssetEmptyUnsetAndDimFetchCompile} so gen-0 split-TU can
 * hollow a smaller Concern TU. Mirrors php-src Zend/zend_compile.c
 * (ZEND_FETCH_DIM_R / ZEND_FETCH_OBJ_* / ZEND_FETCH_STATIC_PROP) and
 * Zend/zend_execute.c quiet property/dim fetch paths.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; fetch
 * slot wiring relies on coercion (same as IssetEmptyUnsetAndDimFetchCompile).
 */
trait PropertyAndDimFetchCompile
{
    /**
     * `@` on builtins with by-ref out args must keep errno/errstr slots after END_SILENCE (#9320, #10336).
     */
    private function inheritErrorSuppressByRefCallArgSlots(
        Block $suppressCompiled,
        Block $endCompiled,
        Op $primary
    ): void {
        if (
            !$primary instanceof Op\Expr\FuncCall
            && !$primary instanceof Op\Expr\NsFuncCall
        ) {
            return;
        }
        if (!property_exists($primary, 'args') || !\is_array($primary->args)) {
            return;
        }
        $name = $this->resolveCfgFuncCallName($primary);
        if (null === $name) {
            return;
        }
        foreach (BuiltinByRefParams::forFunction($name) as $argIndex) {
            $arg = $primary->args[$argIndex] ?? null;
            if (!$arg instanceof Operand) {
                continue;
            }
            $slot = $this->errorSuppressByRefArgSendSlot($suppressCompiled, $primary, (int) $argIndex)
                ?? $suppressCompiled->slotForOperand($arg);
            if (null === $slot) {
                continue;
            }
            $endCompiled->forceBindScopeSlot($arg, $slot);
            $root = Block::cfgVarRoot($arg);
            if (null !== $root) {
                $endCompiled->forceBindScopeSlot($root, $slot);
            }
        }
    }

    /**
     * ARG_SEND slot for a by-ref callee arg inside an {@see ErrorSuppressBlock} (#9320).
     */
    private function errorSuppressByRefArgSendSlot(
        Block $suppressCompiled,
        Op $primary,
        int $argIndex
    ): ?int {
        unset($primary);
        $inCall = false;
        $sendOrdinal = -1;
        $funcCallInits = 0;
        foreach ($suppressCompiled->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                ++$funcCallInits;
                if (1 === $funcCallInits) {
                    $inCall = true;
                    $sendOrdinal = -1;
                }
                continue;
            }
            if (!$inCall) {
                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                ++$sendOrdinal;
                if ($sendOrdinal === $argIndex) {
                    return (int) $op->arg1;
                }
                continue;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type) {
                break;
            }
        }

        return null;
    }

    /**
     * trim($obj->prop) — php-cfg hoists PropertyFetch as its own stmt with a dead arg temp (#14467, libxml).
     */
    private function syncPropertyFetchResultToFollowingFuncCallArg(
        Op\Expr\PropertyFetch $fetch,
        Block $block
    ): void {
        if (null === $block->orig) {
            return;
        }
        $fetchIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $fetch, $block->orig);
        if (!is_int($fetchIndex)) {
            return;
        }
        $next = $block->orig->children[$fetchIndex + 1] ?? null;
        if (!$next instanceof Op\Expr\FuncCall && !$next instanceof Op\Expr\NsFuncCall) {
            return;
        }
        $fetchSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $fetch);
        if (null === $fetchSlot) {
            foreach (array_reverse($block->opCodes) as $opCode) {
                if (OpCode::TYPE_PROPERTY_FETCH === $opCode->type && null !== $opCode->arg1) {
                    $fetchSlot = (int) $opCode->arg1;
                    break;
                }
            }
        }
        if (null === $fetchSlot) {
            $fetchSlot = $block->slotForOperand($fetch->result);
        }
        if (null === $fetchSlot) {
            return;
        }
        if (null !== $fetch->result) {
            $block->bindOperandScopeSlot($fetch->result, $fetchSlot);
        }
        if (!property_exists($next, 'args') || !is_array($next->args)) {
            return;
        }
        foreach ($next->args as $argIndex => $arg) {
            if (!$arg instanceof Operand) {
                continue;
            }
            if (!$this->propertyFetchFuncCallArgUsesHoistedFetch($arg, (int) $argIndex, $fetch, $next)) {
                continue;
            }
            $block->bindOperandScopeSlot($arg, $fetchSlot);
            $this->registerSyncedCoalesceFuncCallArgSlot($arg, $fetchSlot);
        }
        if (null !== $fetch->result) {
            $this->registerSyncedCoalesceFuncCallArgSlot($fetch->result, $fetchSlot);
        }
    }

    /**
     * Hoisted PropertyFetch before FuncCall — only the consumer arg gets the fetch slot (#14467, #18427).
     *
     * implode(',', $obj->items) must not rewire the separator literal to the property temp.
     */
    private function propertyFetchFuncCallArgUsesHoistedFetch(
        Operand $arg,
        int $argIndex,
        Op\Expr\PropertyFetch $fetch,
        Op $callOp
    ): bool {
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return false;
        }
        if (null !== $fetch->result && $this->operandsReferToSameVariable($arg, $fetch->result)) {
            return true;
        }
        if (!$this->callArgIsDeadInlineTemporary($arg)) {
            return false;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return false;
        }
        $deadTempIndices = [];
        foreach ($callOp->args as $i => $candidate) {
            if (!$candidate instanceof Operand || $this->isEmbeddedCallLiteralArg($candidate)) {
                continue;
            }
            if ($this->callArgIsDeadInlineTemporary($candidate)) {
                $deadTempIndices[] = (int) $i;
            }
        }

        return 1 === \count($deadTempIndices) && (int) $argIndex === $deadTempIndices[0];
    }

    /** Last hoisted TYPE_PROPERTY_FETCH result slot in $block (trim($obj->prop) dead-arg wiring). */
    private function lastPropertyFetchResultSlotBeforePendingCall(Block $block): ?string
    {
        foreach (array_reverse($block->opCodes) as $opCode) {
            if (OpCode::TYPE_PROPERTY_FETCH === $opCode->type && null !== $opCode->arg1) {
                return (string) $opCode->arg1;
            }
        }

        return null;
    }

    /**
     * Hoisted PropertyFetch before MethodCall/FuncCall when scalar preludes sit between (#18860).
     *
     * importNode($doc->documentElement->firstChild, true) — ConstFetch between chain and call.
     *
     * @return Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch|null
     */
    private function propertyFetchPreludeMatchingCallArg(
        Block $block,
        Op $cfgCallOp,
        int $callIndex,
        int $argIndex,
        Operand $arg
    ): Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch|null {
        if (null === $block->orig || $callIndex < 1 || !property_exists($cfgCallOp, 'args')) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        if (!\is_array($callArgs) || !$this->callArgIsDeadInlineTemporary($arg)) {
            return null;
        }
        // importNode($doc->documentElement->firstChild, true) — hoisted true is not a PropertyFetch arg (#18860, re-open).
        if (null !== $this->tryFoldHoistedBoolNullLiteralCallArg($arg, $block, $cfgCallOp, $argIndex)) {
            return null;
        }
        $nonLiteralArgCount = 0;
        foreach ($callArgs as $callArg) {
            if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                ++$nonLiteralArgCount;
            }
        }
        $trailingScalarPreludeCount = 0;
        $scalarProbeIndex = $callIndex - 1;
        while ($scalarProbeIndex >= 0) {
            $scalarProbe = $block->orig->children[$scalarProbeIndex] ?? null;
            if ($scalarProbe instanceof Op\Expr\ConstFetch || $scalarProbe instanceof Op\Expr\ClassConstFetch) {
                ++$trailingScalarPreludeCount;
                --$scalarProbeIndex;
                continue;
            }
            break;
        }
        $propertyArgCount = max(0, $nonLiteralArgCount - $trailingScalarPreludeCount);
        $fetches = [];
        $probeIndex = $callIndex - 1;
        while ($probeIndex >= 0) {
            $probe = $block->orig->children[$probeIndex] ?? null;
            if ($probe instanceof Op\Expr\ConstFetch || $probe instanceof Op\Expr\ClassConstFetch) {
                --$probeIndex;
                continue;
            }
            if (
                $probe instanceof Op\Expr\PropertyFetch
                || $probe instanceof Op\Expr\NullsafePropertyFetch
            ) {
                $fetches[] = $probe;
                --$probeIndex;
                continue;
            }
            break;
        }
        if ([] === $fetches) {
            return null;
        }
        // Chained $obj->a->b hoists intermediate PropertyFetches that feed the next
        // fetch's receiver, not call args. Keep only leaf fetches (#19719, #18860).
        $leafFetches = [];
        foreach ($fetches as $i => $fetch) {
            $feedsNearerFetch = false;
            for ($j = 0; $j < $i; ++$j) {
                $nearer = $fetches[$j];
                if (
                    null !== $fetch->result
                    && property_exists($nearer, 'var')
                    && null !== $nearer->var
                    && $this->operandsReferToSameVariable($fetch->result, $nearer->var)
                ) {
                    $feedsNearerFetch = true;
                    break;
                }
            }
            if (!$feedsNearerFetch) {
                $leafFetches[] = $fetch;
            }
        }
        $fetches = $leafFetches;
        // MethodCall receiver PropertyFetch is hoisted with arg fetches but is not a call arg
        // (documentElement->insertBefore($d2->…->firstChild, $d1->…->firstChild)) (#22710).
        if ($cfgCallOp instanceof Op\Expr\MethodCall && null !== $cfgCallOp->var) {
            $receiverVar = $cfgCallOp->var;
            $fetches = \array_values(\array_filter(
                $fetches,
                function ($fetch) use ($receiverVar): bool {
                    return null === $fetch->result
                        || !$this->operandsReferToSameVariable($receiverVar, $fetch->result);
                }
            ));
        }
        // $a->parentNode->replaceChild($b->cloneNode(true), $a) — parentNode feeds the *outer*
        // MethodCall receiver, not cloneNode's bool arg. Drop PropertyFetches consumed as
        // later sibling MethodCall receivers (#25876).
        if (\is_int($callIndex) && null !== $block->orig) {
            $laterReceiverVars = [];
            for ($later = $callIndex + 1, $nChildren = \count($block->orig->children); $later < $nChildren; ++$later) {
                $laterOp = $block->orig->children[$later] ?? null;
                if (
                    $laterOp instanceof Op\Expr\MethodCall
                    && null !== $laterOp->var
                ) {
                    $laterReceiverVars[] = $laterOp->var;
                }
            }
            if ([] !== $laterReceiverVars) {
                $fetches = \array_values(\array_filter(
                    $fetches,
                    function ($fetch) use ($laterReceiverVars): bool {
                        if (null === $fetch->result) {
                            return true;
                        }
                        foreach ($laterReceiverVars as $laterVar) {
                            if ($this->operandsReferToSameVariable($laterVar, $fetch->result)) {
                                return false;
                            }
                        }

                        return true;
                    }
                ));
            }
        }
        if ([] === $fetches) {
            return null;
        }
        if (\count($fetches) > $propertyArgCount) {
            // Extra leaf is usually the MethodCall receiver (documentElement->C14NFile($tmp)).
            // When every non-literal arg is a scalar ConstFetch (propertyArgCount===0), leftovers
            // are outer receivers — not this call's args (#25876 nested cloneNode(true/false)).
            if (0 !== $argIndex || 0 === $propertyArgCount) {
                return null;
            }

            return $fetches[0];
        }
        // Map leaf PropertyFetches onto dead-temp arg indices (skip embedded literals).
        // two('L', $el->tagName) after @$doc->loadXML() — raw argIndex 1 must match the
        // sole dead-temp slot, not firstPropertyArgIndex=0 (#21439, re-#19719/#18860).
        $deadTempArgIndices = [];
        foreach ($callArgs as $i => $callArg) {
            if (
                $callArg instanceof Operand
                && !$this->isEmbeddedCallLiteralArg($callArg)
                && $this->callArgIsDeadInlineTemporary($callArg)
            ) {
                $deadTempArgIndices[] = (int) $i;
            }
        }
        $propertyDeadTempArgIndices = [];
        foreach ($deadTempArgIndices as $deadIdx) {
            $deadArg = $callArgs[$deadIdx] ?? null;
            if (
                $deadArg instanceof Operand
                && null !== $this->tryFoldHoistedBoolNullLiteralCallArg($deadArg, $block, $cfgCallOp, $deadIdx)
            ) {
                continue;
            }
            $propertyDeadTempArgIndices[] = $deadIdx;
        }
        // trailingScalarPreludeCount already subtracts ConstFetch/ClassConstFetch (incl. LIBXML_*),
        // but tryFold only drops true/false/null — trim so saveXML($el->prop, LIBXML_*) does not
        // bind the PropertyFetch slot to the options arg (#25292).
        if (\count($propertyDeadTempArgIndices) > $propertyArgCount) {
            $propertyDeadTempArgIndices = \array_slice($propertyDeadTempArgIndices, 0, $propertyArgCount);
        }
        $deadOrdinal = array_search($argIndex, $propertyDeadTempArgIndices, true);
        if (!\is_int($deadOrdinal)) {
            return null;
        }
        // MethodCall/FuncCall producers fill leading dead-temp args; PropertyFetch
        // leaves map to the trailing propertyArgCount slots (#19719):
        // insertBefore($d->createElement('x'), $r->lastChild).
        $fetchCount = \count($fetches);
        $firstFetchDeadOrdinal = \count($propertyDeadTempArgIndices) - $fetchCount;
        if ($deadOrdinal < $firstFetchDeadOrdinal) {
            return null;
        }
        $ordinal = $fetchCount - 1 - ($deadOrdinal - $firstFetchDeadOrdinal);
        if ($ordinal < 0 || $ordinal >= $fetchCount) {
            return null;
        }

        return $fetches[$ordinal];
    }

    /**
     * @return ?string scope slot for a hoisted PropertyFetch call arg (#18860)
     */
    private function propertyFetchPreludeResultSlot(
        Block $block,
        Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch $prelude,
        Op $cfgCallOp
    ): ?string {
        if (null === $block->slotForOperand($prelude->result)) {
            foreach ($this->compileExpr($prelude, $block) as $op) {
                $block->addOpCode($op);
            }
        }

        // Prefer this prelude's operand slot. Looking back for the last TYPE_PROPERTY_FETCH
        // collapses distinct PropertyFetch call args onto one temp — e.g. peek($a->x, $b->x)
        // and insertBefore($d2->documentElement->firstChild, $d1->…->firstChild) (#22710).
        $operandSlot = $block->slotForOperand($prelude->result);
        if (null !== $operandSlot) {
            return (string) $operandSlot;
        }

        return $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude)
            ?? $this->slotForInlineCallArgProducerResult(
                $block,
                $prelude,
                $cfgCallOp,
                null !== $block->orig ? $block->orig->children : null
            );
    }

    private function compileStaticPropertyFetchRead(
        Op\Expr\StaticPropertyFetch $fetch,
        Block $block,
        bool $propertyHookCoalesceRead = false
    ): void {
        $op = new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        );
        $this->assignSourceMetadata($op, $fetch);
        if ($propertyHookCoalesceRead) {
            $op->propertyHookCoalesceRead = true;
        }
        $block->addOpCode($op);
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when backing is null, #6472).
     */
    private function compilePropertyFetchWrite(Op\Expr\PropertyFetch $fetch, Block $block): void
    {
        $this->rejectTemporaryExpressionInWriteContext($fetch->result, $block, $fetch);
        $block->addOpCode(new OpCode(
            OpCode::TYPE_PROPERTY_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileOperand($fetch->var, $block, true),
            $this->compileOperand($fetch->name, $block, true)
        ));
    }

    private function compileStaticPropertyFetchWrite(Op\Expr\StaticPropertyFetch $fetch, Block $block): void
    {
        $block->addOpCode(new OpCode(
            OpCode::TYPE_STATIC_PROPERTY_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileStaticPropertyNameSlot($fetch->name, $fetch->class, $block)
        ));
    }

    /**
     * Emit a read fetch in $block (used by ?? left branch when the stmt fetch was skipped).
     *
     * @param bool $skipFloatKeyDeprecation When true, isset already warned for this dim (#29664).
     */
    private function compileArrayDimFetchRead(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block,
        bool $skipFloatKeyDeprecation = false
    ): void {
        $this->rejectArrayEmptyOffsetRead($fetch, $block);
        $op = new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileArrayDimFetchContainerSlot($fetch, $block),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        );
        $op->arrayDimFetchSkipFloatKeyDeprecation = $skipFloatKeyDeprecation;
        $this->assignSourceMetadata($op, $fetch);
        $block->addOpCode($op);
    }

    /**
     * Emit a write fetch in $block (used by ??= right branch when the key is absent, issue #1235).
     */
    private function compileArrayDimFetchWrite(Op\Expr\ArrayDimFetch $fetch, Block $block): void
    {
        $this->rejectGlobalsAppend($fetch, $block);
        $this->rejectTemporaryExpressionInWriteContext($fetch->result, $block, $fetch);
        $op = new OpCode(
            OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
            $this->compileOperand($fetch->result, $block, false),
            $this->compileArrayDimFetchContainerSlot($fetch, $block),
            null !== $fetch->dim ? $this->compileOperand($fetch->dim, $block, true) : null
        );
        $this->assignSourceMetadata($op, $fetch);
        $block->addOpCode($op);
    }

    /**
     * FETCH_DIM_W for each dim in an outermost-first nested ??= write chain (#28954).
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain
     */
    private function compileArrayDimFetchWriteChain(array $chain, Block $block): void
    {
        foreach ($chain as $fetch) {
            $this->compileArrayDimFetchWrite($fetch, $block);
        }
    }

    /**
     * Read each dim in an outermost-first nested ?? / ??= left branch (#28954).
     *
     * @param list<Op\Expr\ArrayDimFetch> $chain
     * @param bool                        $skipFloatKeyDeprecation Last-dim isset already warned (#29664).
     */
    private function compileArrayDimFetchReadChain(
        array $chain,
        Block $block,
        bool $skipFloatKeyDeprecation = false
    ): void {
        $last = count($chain) - 1;
        foreach ($chain as $i => $fetch) {
            // Only the final dim shares the coalesce isset probe; prefixes used quiet FETCH_DIM_IS.
            $this->compileArrayDimFetchRead(
                $fetch,
                $block,
                $skipFloatKeyDeprecation && $i === $last
            );
        }
    }

    /**
     * Compile dim-fetch for call-arg wiring — force write fetch for by-ref builtins (#4512).
     */
    private function compileArrayDimFetchForCallArg(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): void {
        $forWrite = false;
        if (null !== $cfgCallOp) {
            $calleeName = $this->funcCallExprCalleeName($cfgCallOp);
            if (null !== $calleeName && $this->callArgRequiresByRef($calleeName, $argIndex, $fetch->result, $block)) {
                $forWrite = true;
            }
        }
        if (!$forWrite) {
            $forWrite = $this->isArrayDimFetchForWrite($fetch, $block);
        }
        if ($forWrite) {
            $this->compileArrayDimFetchWrite($fetch, $block);
        } else {
            foreach ($this->compileExpr($fetch, $block) as $op) {
                $block->addOpCode($op);
            }
        }
    }

    /**
     * Container slot for array dim fetch/write opcodes.
     *
     * PhiResolver may clear {@see Op\Expr\ArrayDimFetch::$var} after param phi merge in large
     * compilation units (PackEngine.parseFormat, #13092). Recover from typed parameters.
     */
    protected function compileArrayDimFetchContainerSlot(Op\Expr\ArrayDimFetch $fetch, Block $block): int
    {
        if (null !== $fetch->var) {
            $slot = $this->compileOperand($fetch->var, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        $recovered = $this->recoverPhiClearedArrayDimFetchContainer($fetch, $block);
        if (null !== $recovered) {
            return $recovered;
        }

        $this->throwCompileLogic('ArrayDimFetch missing container operand');
    }

    /**
     * PhiResolver postprocessor can leave ArrayDimFetch.var null while the container CV remains
     * a typed parameter (php-cfg/Visitor/PhiResolver.php, #13092).
     */
    private function recoverPhiClearedArrayDimFetchContainer(
        Op\Expr\ArrayDimFetch $fetch,
        Block $block
    ): ?int {
        if (null !== $fetch->var || null === $block->func || [] === $block->func->params) {
            return null;
        }

        $preferred = $this->isArrayAppendDim($fetch->dim) ? 'array' : 'string';
        foreach ($block->func->params as $param) {
            if (null === $param->result) {
                continue;
            }
            $decl = $this->declNameFromCfgType($param->declaredType ?? null);
            if ('array' === $preferred) {
                if ('array' !== $decl && null === $this->genericArraySpecFromCfgType($param->declaredType ?? null)) {
                    continue;
                }
            } elseif ('string' !== $decl) {
                continue;
            }
            $slot = $this->compileOperand($param->result, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        foreach ($block->func->params as $param) {
            if (null === $param->result) {
                continue;
            }
            $slot = $this->compileOperand($param->result, $block, true);
            if (null !== $slot) {
                return $slot;
            }
        }

        if ($this->isArrayAppendDim($fetch->dim) || null === $fetch->dim) {
            $emptyInit = $this->recoverEmptyArrayInitLocalOperand($block->func);
            if (null !== $emptyInit) {
                $slot = $this->compileOperand($emptyInit, $block, true);
                if (null !== $slot) {
                    return $slot;
                }
            }
        }

        if (null !== $fetch->dim) {
            $dimRoot = Block::cfgVarRoot($fetch->dim);
            $dimName = null !== $dimRoot ? Block::resolveVariableName($dimRoot) : null;
            $call = 'currentArg' === $dimName
                ? $this->findFuncCallFirstArgOperand($block->func, 'count')
                : $this->findFuncCallFirstArgOperand($block->func, 'strlen');
            if (null !== $call) {
                $slot = $this->compileOperand($call, $block, true);
                if (null !== $slot) {
                    return $slot;
                }
            }
        }

        return null;
    }

    private function recoverEmptyArrayInitLocalOperand(CfgFunc $func): ?Operand
    {
        if (null === $func->cfg) {
            return null;
        }
        $emptyInits = [];
        $walk = function ($node) use (&$walk, &$emptyInits): void {
            if ($node instanceof CfgBlock) {
                $children = $node->children;
                foreach ($children as $i => $child) {
                    if (
                        $child instanceof Op\Expr\Assign
                        && $i > 0
                        && $children[$i - 1] instanceof Op\Expr\Array_
                    ) {
                        $arr = $children[$i - 1];
                        if ([] === $arr->keys && [] === $arr->values && $arr->result === $child->expr) {
                            $emptyInits[] = $child->var;
                        }
                    }
                    $walk($child);
                }
            }
            if ($node instanceof Op\Stmt\JumpIf) {
                $walk($node->if);
                $walk($node->else);
            }
            if ($node instanceof Op\Stmt\Loop) {
                $walk($node->loop);
            }
            if ($node instanceof Op\Stmt\Foreach_) {
                $walk($node->loop);
            }
        };
        $walk($func->cfg);

        return 1 === count($emptyInits) ? $emptyInits[0] : null;
    }
}
