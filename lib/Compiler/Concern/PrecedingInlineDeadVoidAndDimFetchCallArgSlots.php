<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * Dead-void method-call producers and preceding dim/property fetch call-arg slots
 * (#36387 / prior #36147).
 *
 * Extracted from {@see PrecedingInlineCallArgProducers} so gen-0 split-TU can hollow
 * a smaller Concern TU ({@see filterDeadVoidStatementMethodCallProducers} through
 * {@see resolvePrecedingArrayDimFetchCallArgSlot}). Leading-callback / haystack
 * producer discovery remains in PrecedingInlineCallArgProducers.
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_compile.c / zend_execute.c ARG_SEND ordering for dead
 * statement-level method calls and array-dim call args — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as PrecedingInlineCallArgProducers).
 */
trait PrecedingInlineDeadVoidAndDimFetchCallArgSlots
{
    /**
     * Drop void statement MethodCalls before a sibling MethodCall inline call-arg (#10778).
     *
     * php-cfg: `$ao->setIteratorClass('X'); echo var_export($ao->getIteratorClass(), true);`
     * hoists both MethodCalls; the void setter must not map to var_export arg 0.
     *
     * Sibling `var_dump($o->f(), $o->f())` also hoists dead-temp MethodCalls — keep those
     * inside the inline-arg distance window (#10816, #9351).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op>       $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function filterDeadVoidStatementMethodCallProducers(array $producers, Op $callOp, array $cfgChildren): array
    {
        if (\count($producers) < 2) {
            return $producers;
        }
        $consumerIndex = null;
        foreach ($cfgChildren as $i => $child) {
            if ($child === $callOp) {
                $consumerIndex = $i;
                break;
            }
        }
        $tempArgCount = 0;
        if (property_exists($callOp, 'args') && is_array($callOp->args)) {
            foreach ($callOp->args as $arg) {
                if ($arg instanceof Operand\Temporary) {
                    ++$tempArgCount;
                }
            }
        }
        if ($tempArgCount < 1 && property_exists($callOp, 'args') && is_array($callOp->args)) {
            $tempArgCount = \count($callOp->args);
        }
        $filtered = [];
        $count = \count($producers);
        for ($i = 0; $i < $count; ++$i) {
            $producer = $producers[$i];
            if (
                $producer instanceof Op\Expr\MethodCall
                && (
                    $this->methodCallIsStmtLevelDiscardPrelude($producer)
                    || (
                        property_exists($producer, 'result')
                        && empty($producer->result->usages)
                        && !$this->methodCallInlineProducerSuppliesCallArgValue($producer)
                    )
                )
                && $i + 1 < $count
                && $producers[$i + 1] instanceof Op\Expr\MethodCall
            ) {
                if (null !== $consumerIndex) {
                    $producerIndex = null;
                    foreach ($cfgChildren as $pi => $child) {
                        if ($child === $producer) {
                            $producerIndex = $pi;
                            break;
                        }
                    }
                    $distance = null !== $producerIndex ? $consumerIndex - $producerIndex : null;
                    if (null !== $distance && $distance <= $tempArgCount) {
                        $filtered[] = $producer;

                        continue;
                    }
                }

                continue;
            }
            $filtered[] = $producer;
        }

        return $filtered;
    }

    /**
     * php-cfg dead temps for inline call args keep inferred value types (#9351, #10816);
     * void statement calls stay inferred:unknown (#10778).
     * StaticCall producers (Fiber::getCurrent() !== null before var_export/print_r) share the same
     * result-type check as MethodCall (#26703).
     */
    private function methodCallInlineProducerSuppliesCallArgValue(Op\Expr\MethodCall|Op\Expr\StaticCall $producer): bool
    {
        if (!property_exists($producer, 'result')) {
            return false;
        }
        $method = $this->staticNameFromOperand($producer->name);
        if (null !== $method && $this->methodCallIsKnownVoidReturn($method)) {
            return false;
        }
        $type = $producer->result->type ?? null;
        if (null === $type) {
            return true;
        }
        if ($type instanceof \PHPTypes\Type) {
            return \PHPTypes\Type::TYPE_UNKNOWN !== $type->type;
        }
        if ($type instanceof Op\Type\Literal) {
            $name = strtolower((string) ($type->name ?? ''));
            if (str_starts_with($name, 'inferred:')) {
                $inner = substr($name, 9);

                return 'unknown' !== $inner && 'void' !== $inner;
            }

            return 'void' !== $name && 'never' !== $name;
        }

        return true;
    }

    /**
     * Empty-usages MethodCall in a mixed PropertyFetch+call arg window is statement-level when it
     * sits outside the trailing dead-temp arg span and every intervening call also has empty
     * usages (appendChild(createElement) before importNode — #24571). Inline dead-temp args such
     * as replaceChild(createElement, getElementsByTagName()->item()) keep a later call with live
     * usages in the window (#25563) or fall inside the dead-temp span (item).
     *
     * PropertyFetch that only feeds a later MethodCall before the consumer (`$el->childNodes`
     * → `item(N)`) is part of the inline arg chain — not a statement boundary (#34436). Breaking
     * on it dropped createElement so both ARG_SENDs bound item().
     *
     * @param list<Op> $cfgChildren
     */
    private function mixedCallArgProducerIsStatementLevelEmptyUsages(
        int $producerIndex,
        int $consumerIndex,
        int $deadInlineArgCount,
        array $cfgChildren
    ): bool {
        if ($deadInlineArgCount > 0 && ($consumerIndex - $producerIndex) <= $deadInlineArgCount) {
            return false;
        }
        for ($scan = $producerIndex + 1; $scan < $consumerIndex; ++$scan) {
            $between = $cfgChildren[$scan] ?? null;
            if (
                $between instanceof Op\Expr\MethodCall
                || $between instanceof Op\Expr\FuncCall
                || $between instanceof Op\Expr\NsFuncCall
                || $between instanceof Op\Expr\StaticCall
            ) {
                if (
                    property_exists($between, 'result')
                    && null !== $between->result
                    && !empty($between->result->usages)
                ) {
                    return false;
                }
                // Empty-usages MethodCall inside the trailing dead-temp span is an inline arg
                // producer (DOMNodeList::item) — keep preceding createElement (#34436).
                if ($deadInlineArgCount > 0 && ($consumerIndex - $scan) <= $deadInlineArgCount) {
                    return false;
                }
                continue;
            }
            if (
                $between instanceof Op\Expr\PropertyFetch
                || $between instanceof Op\Expr\NullsafePropertyFetch
            ) {
                // childNodes → item(N) chain: keep scanning (#34436). Leaf lastChild / prior
                // statement PropertyFetch still ends the window.
                if ($this->propertyFetchFeedsCallProducerBeforeConsumer(
                    $between,
                    $scan,
                    $consumerIndex,
                    $cfgChildren
                )) {
                    continue;
                }
                break;
            }
            if (
                $between instanceof Op\Expr\ConstFetch
                || $between instanceof Op\Expr\ClassConstFetch
            ) {
                break;
            }
        }

        return true;
    }

    /**
     * True when $fetch's result is the receiver of a MethodCall/StaticCall before $consumerIndex
     * (e.g. `$el->childNodes` feeding `item(N)` in insertBefore args — #34436).
     *
     * @param list<Op> $cfgChildren
     */
    private function propertyFetchFeedsCallProducerBeforeConsumer(
        Op\Expr\PropertyFetch|Op\Expr\NullsafePropertyFetch $fetch,
        int $fetchIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $fetch->result) {
            return false;
        }
        for ($i = $fetchIndex + 1; $i < $consumerIndex; ++$i) {
            $op = $cfgChildren[$i] ?? null;
            if (
                !(
                    $op instanceof Op\Expr\MethodCall
                    || $op instanceof Op\Expr\StaticCall
                )
            ) {
                continue;
            }
            if (
                $op instanceof Op\Expr\MethodCall
                && null !== $op->var
                && $this->operandsReferToSameVariable($fetch->result, $op->var)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a MethodCall should be skipped while walking the hoisted sibling call-arg chain.
     *
     * Typed producers (createElement → DOMElement) always stay. inferred:unknown results are kept
     * when they fall inside the trailing dead-temp arg window (DOMNodeList::item(), getElementById(),
     * … — #21171) and skipped when they are prior statement calls such as loadXML (#19719).
     */
    /**
     * @param list<Op> $cfgChildren
     */
    private function methodCallIsSkippedHoistedSiblingProducer(
        Op\Expr\MethodCall $child,
        int $childIndex,
        int $consumerIndex,
        int $deadInlineArgCount,
        array $cfgChildren = []
    ): bool {
        if ($this->methodCallHasStatementLevelSideEffects($child)) {
            // Iterator `$it->next(); var_export($it->current(), true)` — outside the dead-temp
            // arg window (#13901). Value-producing `f($o->next(), $o->next())` stays (#25672).
            return $deadInlineArgCount < 1
                || ($consumerIndex - $childIndex) > $deadInlineArgCount;
        }
        $method = $this->staticNameFromOperand($child->name);
        if (null !== $method && $this->methodCallIsKnownVoidReturn($method)) {
            return true;
        }
        if ($this->methodCallIsStmtLevelDiscardPrelude($child)) {
            return $deadInlineArgCount < 1
                || ($consumerIndex - $childIndex) > $deadInlineArgCount;
        }
        // Unused `$d->appendChild($d->createElement('root')); $d->importNode($src->documentElement, true)`
        // — typed createElement/appendChild still look like call-arg producers (DOMElement/DOMNode),
        // but PropertyFetch/ConstFetch before the consumer mark them as prior statements. Re-emitting
        // them as importNode nested producers duplicates the tree (phantom sibling / trailing
        // `<root/>` in saveXML) (#34405 / re-#24571). Keep replaceChild(createElement, item) — no
        // PF/Const between.
        if (
            [] !== $cfgChildren
            && (null === $child->result || empty($child->result->usages))
            && $this->emptyUsagesDomMutationIsPriorStatementBeforeConsumer(
                $child,
                $childIndex,
                $consumerIndex,
                $cfgChildren
            )
        ) {
            return true;
        }
        if ($this->methodCallInlineProducerSuppliesCallArgValue($child)) {
            return false;
        }
        // getElementsByTagName()->item(0) before importNode(..., true) — keep the receiver
        // producer even when trailing ConstFetch pushes raw distance past deadInlineArgCount
        // (#25702, re-#20284/#25605).
        if (
            [] !== $cfgChildren
            && null !== $child->result
            && !empty($child->result->usages)
        ) {
            foreach ($child->result->usages as $usage) {
                if (!($usage instanceof Op\Expr\MethodCall || $usage instanceof Op\Expr\StaticCall)) {
                    continue;
                }
                $usageIndex = array_search($usage, $cfgChildren, true);
                // Include usageIndex === consumerIndex: auto-detected "consumer" may be the leaf
                // MethodCall (isId) whose var is this receiver — not the outer FuncCall (#25928).
                if (\is_int($usageIndex) && $usageIndex > $childIndex && $usageIndex <= $consumerIndex) {
                    return false;
                }
            }
        }
        if ($deadInlineArgCount < 1) {
            return true;
        }
        $distance = $consumerIndex - $childIndex;
        // true/false/null ConstFetch between chained MethodCalls and the consumer occupy CFG
        // slots without being call producers — do not let them push the leaf chain out of window.
        if ([] !== $cfgChildren) {
            for ($j = $childIndex + 1; $j < $consumerIndex; ++$j) {
                $mid = $cfgChildren[$j] ?? null;
                if ($mid instanceof Op\Expr\ConstFetch || $mid instanceof Op\Expr\ClassConstFetch) {
                    --$distance;
                }
            }
        }

        return $distance > $deadInlineArgCount;
    }

    /** Count php-cfg dead inline call-arg temporaries on a consumer (#9463, #25672). */
    private function deadInlineTemporaryArgCount(?Op $consumer): int
    {
        if (null === $consumer || !property_exists($consumer, 'args') || !\is_array($consumer->args)) {
            return 0;
        }
        $cacheKey = spl_object_id($consumer);
        if (\array_key_exists($cacheKey, $this->deadInlineTemporaryArgCountCache)) {
            return $this->deadInlineTemporaryArgCountCache[$cacheKey];
        }
        $count = 0;
        foreach ($consumer->args as $arg) {
            if ($this->callArgIsDeadInlineTemporary($arg)) {
                ++$count;
            }
        }
        $this->deadInlineTemporaryArgCountCache[$cacheKey] = $count;

        return $count;
    }

    /** php-cfg may leave void method results untyped; do not wire them as inline call-arg values (#10778). */
    private function methodCallIsKnownVoidReturn(string $method): bool
    {
        return in_array(strtolower($method), [
            'setiteratorclass',
        ], true);
    }

    // --- Array-dim / property call-arg slot helpers (#10212 / #36380 / #36403) ---

    /** Call args rooted at array dim fetch must use their own producer slot (#10212). */
    private function isCallArgDirectArrayDimFetch(Operand $arg): bool
    {
        return $this->unwrapOperandChain($arg) instanceof Op\Expr\ArrayDimFetch;
    }

    /** Call args rooted at property fetch must use their own producer slot (#25301). */
    private function isCallArgDirectPropertyFetch(Operand $arg): bool
    {
        return $this->unwrapOperandChain($arg) instanceof Op\Expr\PropertyFetch;
    }

    /**
     * bump($obj->prop) — by-ref call args need FETCH_OBJ_W (#25301, zend_execute.c ZEND_SEND_REF).
     *
     * @return list<OpCode>
     */
    private function compileCallArgPropertyFetch(
        Op\Expr\PropertyFetch $fetch,
        Block $block,
        ?string $calleeName,
        int $argIndex
    ): array {
        $forceWrite = null !== $calleeName
            && $this->callArgRequiresByRef($calleeName, $argIndex, $fetch->result, $block);
        if ($forceWrite) {
            ++$this->forcePropertyFetchForWrite;
        }
        try {
            return $this->compileExpr($fetch, $block);
        } finally {
            if ($forceWrite) {
                --$this->forcePropertyFetchForWrite;
            }
        }
    }

    /**
     * php-cfg may wire FuncCall args to dead temps while dim-fetch producers sit immediately
     * before the call (#10212, ext/standard/array.c usort comparators).
     */
    private function resolvePrecedingArrayDimFetchCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex
    ): ?string {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        // Embedded literals are not dim-fetch producers (#10401, zend_execute.c).
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return null;
        }
        // Named CVs / by-ref out-params are never dim-fetch results. Without this guard,
        // preg_match('/x/', $ex['t'], $matches) maps &$matches onto the subject dim — writeback
        // clobbers $ex['t'] and $matches stays unset (Parsedown automatic_link hang, #36380;
        // same #23354 operand-steal class as f($x+1, $r['k'])).
        $callArgAtIndex = (
            \is_array($cfgCallOp->args ?? null)
            && \array_key_exists($argIndex, $cfgCallOp->args)
            && $cfgCallOp->args[$argIndex] instanceof Operand
        ) ? $cfgCallOp->args[$argIndex] : $arg;
        if (
            !$this->callArgIsDeadInlineTemporary($callArgAtIndex)
            && !$this->isCallArgDirectArrayDimFetch($callArgAtIndex)
        ) {
            return null;
        }
        $inlineLiteralFetchSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
            $block,
            $cfgCallOp,
            $argIndex
        );
        if (null !== $inlineLiteralFetchSlot) {
            return $inlineLiteralFetchSlot;
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
            return null;
        }
        /** @var list<Op\Expr\ArrayDimFetch> $dimFetches */
        $dimFetches = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                array_unshift($dimFetches, $child);
                continue;
            }
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
            ) {
                // var_export($a[1][0], true) — hoisted literal between dim chain and call (#10495, #15762).
                continue;
            }
            break;
        }
        if ($cfgCallOp instanceof Op\Expr\MethodCall || $cfgCallOp instanceof Op\Expr\NullsafeMethodCall) {
            // Receiver dim-fetch preludes are not call-arg producers ($tokens[1]->is(T_ECHO); #9703).
            $dimFetches = array_values(array_filter(
                $dimFetches,
                fn (Op\Expr\ArrayDimFetch $fetch): bool => !$this->arrayDimFetchFeedsMethodCallReceiver(
                    $fetch,
                    $cfgCallOp->var
                )
            ));
        }
        if ([] === $dimFetches) {
            return null;
        }
        if (
            \count($dimFetches) > 1
            && $this->arrayDimFetchesFormProducerChain($dimFetches)
        ) {
            // var_export($a[1][0], true) — chain tail feeds arg #0; trailing literal is not a dim-fetch (#15762, #15945).
            if ($argIndex > 0) {
                return null;
            }
            $opcodeDimIndex = \count($dimFetches) - 1;
            $fetch = $dimFetches[$opcodeDimIndex];
            $slot = $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $opcodeDimIndex);
            if (null === $slot) {
                $slot = $block->slotForOperand($fetch->result);
            }
            if (null === $slot) {
                $this->compileArrayDimFetchForCallArg($fetch, $block, $cfgCallOp, (int) $argIndex);
                $slot = $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $opcodeDimIndex)
                    ?? $block->slotForOperand($fetch->result);
            }

            return null !== $slot ? (string) $slot : null;
        }
        $callArgs = property_exists($cfgCallOp, 'args') && is_array($cfgCallOp->args)
            ? $cfgCallOp->args
            : [];
        $dimIndex = $argIndex;
        if (\count($dimFetches) < \count($callArgs)) {
            // Only args that can be dim-fetch results (dead temps / direct dims). Including a
            // trailing named &$matches made the last-alignment steal the subject dim (#36380).
            $nonEmbeddedArgIndices = [];
            foreach ($callArgs as $i => $callArg) {
                if (
                    null !== $callArg
                    && !$this->isEmbeddedCallLiteralArg($callArg)
                    && (
                        $this->callArgIsDeadInlineTemporary($callArg)
                        || $this->isCallArgDirectArrayDimFetch($callArg)
                    )
                ) {
                    $nonEmbeddedArgIndices[] = $i;
                }
            }
            $mapped = array_search($argIndex, $nonEmbeddedArgIndices, true);
            if (false === $mapped) {
                return null;
            }
            // The collected fetches are the ones immediately before the call, so they belong to the
            // LAST non-embedded arguments, not the first. Aligning them head-first gave arg #0 the
            // trailing argument's fetch: f($x + 1, $r['k']) printed "K|K" (#23354).
            $dimIndex = (int) $mapped - (\count($nonEmbeddedArgIndices) - \count($dimFetches));
            if ($dimIndex < 0) {
                return null;
            }
        }
        if (!isset($dimFetches[$dimIndex])) {
            return null;
        }
        $opcodeDimIndex = $dimIndex;
        $fetch = $dimFetches[$dimIndex];
        $slot = $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $opcodeDimIndex);
        if (null === $slot) {
            $slot = $block->slotForOperand($fetch->result);
        }
        if (null === $slot) {
            $this->compileArrayDimFetchForCallArg($fetch, $block, $cfgCallOp, (int) $argIndex);
            $slot = $this->compiledArrayDimFetchResultSlotBeforePendingFuncCall($block, $opcodeDimIndex)
                ?? $block->slotForOperand($fetch->result);
        }

        return null !== $slot ? (string) $slot : null;
    }
}
