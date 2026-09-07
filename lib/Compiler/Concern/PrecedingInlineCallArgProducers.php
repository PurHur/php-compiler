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
 * Preceding inline call-arg producer discovery hub (#36387 / #36403).
 *
 * Nested / IIFE / deferred-sibling predicates live in
 * {@see NestedIifeAndDeferredSiblingCallArgProducers} (split-TU hollow).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Dead-void / dim-fetch call-arg helpers live in
 * {@see PrecedingInlineDeadVoidAndDimFetchCallArgSlots} (split-TU hollow).
 *
 * CFG children op-index cache and leading-callback / haystack producers live in
 * {@see PrecedingInlineLeadingCallbackAndHaystackProducers} (split-TU hollow).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait PrecedingInlineCallArgProducers
{
    /**
     * php-cfg dead call-arg temps: inline producers immediately before the call (#8561, #4633).
     *
     * @param list<Op> $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function precedingInlineCallArgProducersBeforeCfgOp(array $cfgChildren, Op $callOp): array
    {
        $cacheKey = spl_object_id($callOp);
        if (isset($this->precedingInlineCallArgProducersCache[$cacheKey])) {
            return $this->precedingInlineCallArgProducersCache[$cacheKey];
        }

        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex) {
            $this->precedingInlineCallArgProducersCache[$cacheKey] = [];

            return [];
        }
        // Bound the backward walk to the hoisted sibling chain for this call. Walking to
        // index 0 re-probed every prior statement's FuncCalls (O(n²) compile) (#36387).
        $scanFloor = 0;
        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($callIndex, $cfgChildren);
        if (null !== $firstSibling) {
            $scanFloor = $firstSibling;
        }
        $producers = [];
        for ($i = $callIndex - 1; $i >= $scanFloor; --$i) {
            $child = $cfgChildren[$i];
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->siblingScanStopsAtPriorFuncCall($child, $callOp, $i, $callIndex, $cfgChildren)
            ) {
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && 'define' === strtolower($this->resolveCfgFuncCallName($child) ?? '')
            ) {
                break;
            }
            if ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                // Stmt-level ?? before the call — dim-fetch tails are not arg producers (#10743, #11601).
                break;
            }
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                break;
            }
            if (
                $child instanceof Op\Expr\ArrowFunction
                || $child instanceof Op\Expr\Closure
                || $child instanceof Op\Expr\FirstClassCallable
            ) {
                if ($callOp instanceof Op\Expr\FuncCall || $callOp instanceof Op\Expr\NsFuncCall) {
                    $calleeOperand = $callOp instanceof Op\Expr\NsFuncCall
                        ? ($callOp->nsName ?? null)
                        : ($callOp->name ?? null);
                    if (
                        null !== $calleeOperand
                        && null !== $child->result
                        && ($calleeOperand === $child->result
                            || $this->operandsReferToSameVariable($calleeOperand, $child->result))
                    ) {
                        continue;
                    }
                }
                // (fn)->bindTo(new C(), …) — inline closure is MethodCall receiver, not arg0 (#15900).
                if ($callOp instanceof Op\Expr\MethodCall) {
                    $receiverOperand = $callOp->var ?? null;
                    if (
                        null !== $receiverOperand
                        && null !== $child->result
                        && ($receiverOperand === $child->result
                            || $this->operandsReferToSameVariable($receiverOperand, $child->result))
                    ) {
                        continue;
                    }
                }
            }
            if (
                $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\New_
            ) {
                $next = $cfgChildren[$i + 1] ?? null;
                if (
                    (
                        $next instanceof Op\Expr\Array_
                        || $next instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $next instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $next instanceof Op\Expr\BinaryOp\BitwiseXor
                    )
                    && $this->cfgExprUsesOperand($next, $child->result)
                ) {
                    // Hoisted operand inside sibling inline Array_ / bitmask call arg (#10612, #11304, #11387).
                    continue;
                }
                // new Outer(new Inner(..., Class::CONST), mode) — const feeds *inner* New_ only (#19439).
                // Do not skip when $next is the consumer itself (outer ctor ClassConstFetch args).
                if (
                    ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                    && $next instanceof Op\Expr\New_
                    && $next !== $callOp
                    && null !== $child->result
                    && $this->cfgExprUsesOperand($next, $child->result)
                ) {
                    continue;
                }
                // php-cfg hoists `E::A` before `E::A::class` — case fetch feeds sibling ::class, not call arg (#9426, #16030).
                if (
                    $child instanceof Op\Expr\ClassConstFetch
                    && $next instanceof Op\Expr\ClassConstFetch
                    && $this->operandsReferToSameVariable($next->class, $child->result)
                ) {
                    $pseudoName = $this->staticNameFromOperand($next->name);
                    if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                        continue;
                    }
                }
                // php-cfg `var_export([NAN, INF], true)` — ConstFetch chain before sibling Array_ (#12824).
                // Stop at true/false/null call-arg ConstFetch so a later sibling Array_ is not treated
                // as an element consumer of earlier named consts (#22368).
                for ($j = $i + 1; $j < $callIndex; ++$j) {
                    $scan = $cfgChildren[$j];
                    if (
                        $scan instanceof Op\Expr\Array_
                        && null !== $child->result
                        && $this->cfgExprUsesOperand($scan, $child->result)
                    ) {
                        continue 2;
                    }
                    if ($scan instanceof Op\Expr\ConstFetch || $scan instanceof Op\Expr\ClassConstFetch) {
                        if (
                            $scan instanceof Op\Expr\ConstFetch
                            && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($scan)
                        ) {
                            break;
                        }
                        continue;
                    }
                    break;
                }
                if (
                    $child instanceof Op\Expr\ConstFetch
                    && ($next instanceof Op\Expr\UnaryMinus || $next instanceof Op\Expr\UnaryPlus)
                    && $next->expr === $child->result
                ) {
                    continue;
                }
                // var_export((int) E::A) — ClassConstFetch prelude feeds sibling Cast, not call arg (#9479, #15982).
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && (
                        $next->expr === $child->result
                        || $this->operandsReferToSameVariable($next->expr, $child->result)
                    )
                ) {
                    continue;
                }
                // var_export(INF*0, true) — scalar ConstFetch feeds sibling Mul, not call arg (#17210).
                if (
                    $child instanceof Op\Expr\ConstFetch
                    && $this->isChainedArithmeticBinaryOpExpr($next)
                    && null !== $child->result
                    && (
                        $next->left === $child->result
                        || $next->right === $child->result
                        || $this->operandsReferToSameVariable($next->left, $child->result)
                        || $this->operandsReferToSameVariable($next->right, $child->result)
                    )
                    && 'var_export' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
                ) {
                    continue;
                }
                // var_export($b[false]) — hoisted bool/null dim is not a separate call arg (#16738, #5275).
                if (
                    ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                    && $next instanceof Op\Expr\ArrayDimFetch
                    && null !== $next->dim
                    && (
                        $next->dim === $child->result
                        || $this->operandsReferToSameVariable($next->dim, $child->result)
                    )
                ) {
                    continue;
                }
                // var_export($x !== false, true) — hoisted false feeds comparison RHS, not call arg (#17250, re-#13694).
                if (
                    ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                    && $this->isComparisonInlineCallArgProducer($next)
                    && null !== $child->result
                    && $this->cfgExprUsesOperand($next, $child->result)
                ) {
                    continue;
                }
                // ConstFetch chain before sibling BitwiseOr — hoisted operands, not call args (#11407).
                for ($j = $i + 1; $j < $callIndex; ++$j) {
                    $scan = $cfgChildren[$j];
                    if (
                        $scan instanceof Op\Expr\BinaryOp\BitwiseOr
                        || $scan instanceof Op\Expr\BinaryOp\BitwiseAnd
                        || $scan instanceof Op\Expr\BinaryOp\BitwiseXor
                    ) {
                        if ($this->cfgExprUsesOperand($scan, $child->result)) {
                            continue 2;
                        }
                        break;
                    }
                    if ($scan instanceof Op\Expr\ConstFetch || $scan instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    break;
                }
                // var_export(atan2(NAN, INF), true) — ConstFetch chain feeds nested sibling FuncCall (#10070).
                for ($j = $i + 1; $j < $callIndex; ++$j) {
                    $scan = $cfgChildren[$j] ?? null;
                    if ($scan instanceof Op\Expr\ConstFetch || $scan instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    if (
                        ($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall)
                        && (
                            $this->isNestedCallArgProducerForConsumer($scan, $callOp, $j, $callIndex, $cfgChildren)
                            || $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                                $scan,
                                $callOp,
                                $j,
                                $callIndex,
                                $cfgChildren
                            )
                            || $this->isSiblingMultiArgFuncCallProducer($scan, $callOp, $j, $callIndex, $cfgChildren)
                        )
                    ) {
                        continue 2;
                    }
                    break;
                }
            }
            // php-cfg `var_export(substr(..., -2), true)` — UnaryMinus feeds sibling FuncCall arg, not consumer (#10373).
            if ($child instanceof Op\Expr\UnaryMinus
                || $child instanceof Op\Expr\UnaryPlus
                || $child instanceof Op\Expr\BitwiseNot
                || $child instanceof Op\Expr\BooleanNot
            ) {
                $next = $cfgChildren[$i + 1] ?? null;
                // php-cfg var_export('abc'[-1], true) — UnaryMinus feeds sibling ArrayDimFetch dim (#16461).
                if (
                    $next instanceof Op\Expr\ArrayDimFetch
                    && null !== $child->result
                    && null !== $next->dim
                    && (
                        $next->dim === $child->result
                        || $this->operandsReferToSameVariable($next->dim, $child->result)
                    )
                ) {
                    continue;
                }
                if (
                    ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)
                    && (
                        $this->isSiblingMultiArgFuncCallProducer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isNestedCallArgProducerForConsumer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isAdjacentNestedFuncCallProducer($next, $callOp, $i + 1, $callIndex)
                    )
                ) {
                    continue;
                }
            }
            if ($child instanceof Op\Expr\Assign) {
                if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
                    break;
                }
                $assignFeedsCallArg = false;
                foreach ($callOp->args as $callArg) {
                    if (
                        $this->operandsReferToSameVariable($child->var, $callArg)
                        || $this->operandsReferToSameVariable($child->result, $callArg)
                    ) {
                        $assignFeedsCallArg = true;
                        break;
                    }
                }
                if (!$assignFeedsCallArg) {
                    break;
                }
                // Prior `$a = [...]; f($a, …)` — not an inline producer for this call (#10579).
                if ($i < $callIndex - 1) {
                    break;
                }
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->builtinUsesTrailingComparatorCallback($this->resolveCfgFuncCallName($child))
            ) {
                // Prior array_udiff*(...) / array_uintersect*(...) stmt — not an arg producer (#16045).
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && !$this->inlineCallArgProducerFeedsConsumer($child, $callOp)
                && !$this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && !$this->isSiblingMultiArgFuncCallProducer($child, $callOp, $i, $callIndex, $cfgChildren)
                && !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                break;
            }
            if (
                ($child instanceof Op\Expr\StaticCall || $child instanceof Op\Expr\MethodCall)
                && !$this->inlineCallArgProducerFeedsConsumer($child, $callOp)
                && !$this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && !$this->isSiblingMultiArgFuncCallProducer($child, $callOp, $i, $callIndex, $cfgChildren)
                && !$this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                if (
                    $child instanceof Op\Expr\MethodCall
                    && null !== ($method = $this->staticNameFromOperand($child->name))
                    && $this->methodCallIsKnownVoidReturn($method)
                ) {
                    continue;
                }
                // var_export($ao->getIteratorClass(), true) — MethodCall before hoisted true/false/null (#10778).
                if (
                    ($child instanceof Op\Expr\MethodCall || $child instanceof Op\Expr\StaticCall)
                    && $i + 1 < $callIndex
                    && ($cfgChildren[$i + 1] ?? null) instanceof Op\Expr\ConstFetch
                    && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($cfgChildren[$callIndex - 1] ?? null)
                    && property_exists($callOp, 'args')
                    && is_array($callOp->args)
                    && count($callOp->args) >= 2
                    && $this->methodCallInlineProducerSuppliesCallArgValue($child)
                ) {
                    array_unshift($producers, $child);
                    continue;
                }
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                array_unshift($producers, $child);
                continue;
            }
            if (
                ($child instanceof Op\Expr\StaticCall || $child instanceof Op\Expr\MethodCall)
                && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                array_unshift($producers, $child);
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && $this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && property_exists($callOp, 'args')
                && is_array($callOp->args)
            ) {
                array_unshift($producers, $child);
                // filter_var(sprintf(...), FILTER_*) — collect hoisted ConstFetch preludes (#13617).
                if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )) {
                    continue;
                }
                // var_dump($g(), $g()) — adjacent hoisted producers are siblings, not nested-only (#13671).
                if ($this->isSiblingMultiArgFuncCallProducer($child, $callOp, $i, $callIndex, $cfgChildren)) {
                    continue;
                }
                // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch prelude before sibling FuncCall (#15833).
                $leadingConst = $cfgChildren[$i - 1] ?? null;
                if (
                    $leadingConst instanceof Op\Expr\ConstFetch
                    && $this->isInlineExprCallArgProducer($leadingConst)
                    && null !== $this->splitLeadingConstFetchWithFuncCallCallArg([$leadingConst, $child])
                ) {
                    array_unshift($producers, $leadingConst);
                }
                break;
            }
            if (
                ($child instanceof Op\Expr\StaticCall || $child instanceof Op\Expr\MethodCall)
                && $this->isNestedCallArgProducerForConsumer($child, $callOp, $i, $callIndex, $cfgChildren)
                && property_exists($callOp, 'args')
                && is_array($callOp->args)
            ) {
                array_unshift($producers, $child);
                if ($this->isSiblingMultiArgFuncCallProducer($child, $callOp, $i, $callIndex, $cfgChildren)) {
                    continue;
                }
                break;
            }
            if (
                ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall)
                && property_exists($callOp, 'args')
                && is_array($callOp->args)
                && count($callOp->args) >= 2
                && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                    $child,
                    $callOp,
                    $i,
                    $callIndex,
                    $cfgChildren
                )
            ) {
                array_unshift($producers, $child);
                break;
            }
            if ($child instanceof Op\Expr\Array_) {
                $next = $cfgChildren[$i + 1] ?? null;
                // (object)[...] / (array)[...] — inner Array_ feeds sibling Cast, not the consumer (#15207).
                if (
                    $next instanceof Op\Expr\Cast
                    && property_exists($next, 'expr')
                    && $this->cfgExprUsesOperand($next, $child->result)
                ) {
                    continue;
                }
                // php-cfg `var_export(array_keys([...]), true)` — Array_ feeds sibling FuncCall arg (#10373).
                if (
                    ($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall
                        || $next instanceof Op\Expr\StaticCall || $next instanceof Op\Expr\MethodCall)
                    && (
                        $this->isSiblingMultiArgFuncCallProducer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isNestedCallArgProducerForConsumer($next, $callOp, $i + 1, $callIndex, $cfgChildren)
                        || $this->isAdjacentNestedFuncCallProducer($next, $callOp, $i + 1, $callIndex)
                    )
                ) {
                    continue;
                }
                // php-cfg `var_export(array_pad([...], -3, 0), true)` — Array_ + UnaryMinus feed nested sibling FuncCall (#10351).
                if ($next instanceof Op\Expr\UnaryMinus || $next instanceof Op\Expr\UnaryPlus) {
                    $afterUnary = $cfgChildren[$i + 2] ?? null;
                    if (
                        ($afterUnary instanceof Op\Expr\FuncCall || $afterUnary instanceof Op\Expr\NsFuncCall)
                        && (
                            $this->isSiblingMultiArgFuncCallProducer($afterUnary, $callOp, $i + 2, $callIndex, $cfgChildren)
                            || $this->isNestedCallArgProducerForConsumer($afterUnary, $callOp, $i + 2, $callIndex, $cfgChildren)
                            || $this->isAdjacentNestedFuncCallProducer($afterUnary, $callOp, $i + 2, $callIndex)
                        )
                    ) {
                        continue;
                    }
                }
                if ($this->hoistedExprFeedsSiblingComparisonBeforeCall($child, $i, $callIndex, $cfgChildren)) {
                    // var_export([1] !== false, true) — Array_ feeds comparison LHS, not call arg (#17277).
                    continue;
                }
                array_unshift($producers, $child);
                $prev = $cfgChildren[$i - 1] ?? null;
                // array_merge(array_keys($src), [...]) — FuncCall producer before trailing sibling Array_ (#12450).
                if (
                    ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
                    && $this->isSiblingMultiArgFuncCallProducer($prev, $callOp, $i - 1, $callIndex, $cfgChildren)
                    && !$this->statementLevelFuncCallBeforeHoistedSiblingChain($i - 1, $callIndex, $cfgChildren)
                ) {
                    array_unshift($producers, $prev);
                    break;
                }
                // array_map(fn(...), [...]) / preg_replace_callback($pat, fn(...), $arr) — Closure/FCC before Array_ (#10651, #10652, #11450).
                if ($prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\FirstClassCallable) {
                    array_unshift($producers, $prev);
                    // array_reduce([...], fn(...), [...]) — CFG is Array_input, Closure, Array_initial.
                    // Breaking here drops the input Array_, so pair-wiring binds initial [] to arg #0 (#5626).
                    if ('array_reduce' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')) {
                        for ($k = $i - 2; $k >= 0; --$k) {
                            $before = $cfgChildren[$k];
                            if (
                                $before instanceof Op\Expr\ClassConstFetch
                                || $before instanceof Op\Expr\ConstFetch
                            ) {
                                // Enum/scalar elements of the input Array_ — keep walking (#5626).
                                continue;
                            }
                            if ($before instanceof Op\Expr\Array_) {
                                array_unshift($producers, $before);
                            }
                            break;
                        }
                    }
                    break;
                }
                // php-cfg: `invokeArgs(new C(), [...])` — New_ immediately precedes Array_ (#9904).
                if ($prev instanceof Op\Expr\New_) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        // New_ is an inline array element — keep walking for closure siblings (#11304).
                        continue;
                    }
                    array_unshift($producers, $prev);
                    break;
                }
                // Sibling inline Array_ call args: `array_replace([...], [...])` (#10231).
                // Nested element literals (`array_column([[...], [...]], ...)`) are not call-arg producers (#9305).
                if ($prev instanceof Op\Expr\Array_) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        // Inner→outer nesting within one inline call arg (#10196, #10662); keep walking for siblings.
                        continue;
                    }
                    $grandPrev = $cfgChildren[$i - 2] ?? null;
                    if (
                        $grandPrev instanceof Op\Expr\Array_
                        && !$this->cfgExprUsesOperand($prev, $grandPrev->result)
                    ) {
                        // 3+ sibling inline array call args — keep walking (#5644).
                        continue;
                    }
                    array_unshift($producers, $prev);

                    break;
                }
                // password_hash(lit, PASSWORD_BCRYPT, [...]) — ConstFetch before trailing Array_ (#10453).
                // openssl_cms_verify(..., FLAGS, null, [$ca]) — collect all leading ConstFetch call-args (#22368).
                if ($prev instanceof Op\Expr\ConstFetch || $prev instanceof Op\Expr\ClassConstFetch) {
                    if ($this->cfgExprUsesOperand($child, $prev->result)) {
                        $grandPrev = $cfgChildren[$i - 2] ?? null;
                        if ($grandPrev instanceof Op\Expr\Array_) {
                            continue;
                        }
                        // Element ConstFetch inside inline Array_ — keep walking for leading call-arg ConstFetch (#12326).
                        continue;
                    }
                    for ($k = $i - 1; $k >= 0; --$k) {
                        $lead = $cfgChildren[$k];
                        if (!($lead instanceof Op\Expr\ConstFetch || $lead instanceof Op\Expr\ClassConstFetch)) {
                            break;
                        }
                        if (null !== $lead->result && $this->cfgExprUsesOperand($child, $lead->result)) {
                            // Array-element ConstFetch — skip, keep scanning older call-arg consts (#12326).
                            continue;
                        }
                        if ($this->isInlineExprCallArgProducer($lead)) {
                            array_unshift($producers, $lead);
                        }
                    }
                    break;
                }
                // call_user_func_array(C::class.'::ok', []) — Concat feeds arg #0, Array_ is arg #1 (#11694).
                if ($prev instanceof Op\Expr\BinaryOp\Concat) {
                    $feedsCallArg = false;
                    if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                        foreach ($callOp->args as $callArg) {
                            if ($this->operandsReferToSameVariable($prev->result, $callArg)) {
                                $feedsCallArg = true;
                                break;
                            }
                        }
                    }
                    if ($feedsCallArg) {
                        array_unshift($producers, $prev);
                    }
                }
                if (
                    ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall)
                    && $this->isNestedCallArgProducerForConsumer($prev, $callOp, $i - 1, $callIndex, $cfgChildren)
                ) {
                    array_unshift($producers, $child);
                    continue;
                }
                // array_splice($a, -2, 1, ['x']) — UnaryMinus offset prelude before replacement Array_ (#9329).
                if ($prev instanceof Op\Expr\UnaryMinus || $prev instanceof Op\Expr\UnaryPlus) {
                    array_unshift($producers, $prev);
                    break;
                }
                break;
            }
            // echo floor(-2.5) . ' ' . ceil(-2.5) — inner Concat does not feed outer FuncCall args (#13494).
            if ($child instanceof Op\Expr\BinaryOp\Concat) {
                if ($this->inlineCallArgProducerFeedsConsumer($child, $callOp)) {
                    array_unshift($producers, $child);
                } else {
                    $concatChain = $this->chainedConcatInlineCallArgProducersBeforeCall(
                        $cfgChildren,
                        $callIndex,
                        $callOp
                    );
                    if (null !== $concatChain) {
                        foreach ($concatChain as $concatProducer) {
                            array_unshift($producers, $concatProducer);
                        }
                    } elseif (
                        property_exists($callOp, 'args')
                        && is_array($callOp->args)
                        && $this->hoistedCallArgsAreDistinctInlineTemporaries($callOp->args)
                        && (
                            ($cfgChildren[$i + 1] ?? null) instanceof Op\Expr\ConstFetch
                            || ($cfgChildren[$i + 1] ?? null) instanceof Op\Expr\ClassConstFetch
                        )
                    ) {
                        // glob($dir.'/*', GLOB_MARK) — Concat + ConstFetch hoisted siblings (#13660).
                        // file_get_contents('data://…,'.$p, false, null, $off, $len) — Concat + ConstFetch preludes (#18613).
                        array_unshift($producers, $child);
                    }
                }
                break;
            }
            // sprintf('%.10F', 5 * 200.0 / 12) — inner Mul does not feed outer FuncCall args (#15929).
            if ($this->isChainedArithmeticBinaryOpExpr($child)) {
                if ($this->inlineCallArgProducerFeedsConsumer($child, $callOp)) {
                    array_unshift($producers, $child);
                } else {
                    $arithmeticChain = $this->chainedArithmeticInlineCallArgProducersBeforeCall(
                        $cfgChildren,
                        $callIndex,
                        $callOp
                    );
                    if (null !== $arithmeticChain) {
                        foreach ($arithmeticChain as $arithmeticProducer) {
                            array_unshift($producers, $arithmeticProducer);
                        }
                    } elseif (
                        ($child instanceof Op\Expr\BinaryOp\BitwiseOr
                            || $child instanceof Op\Expr\BinaryOp\BitwiseAnd
                            || $child instanceof Op\Expr\BinaryOp\BitwiseXor)
                        && (
                            $i === $callIndex - 1
                            || (
                                $i === $callIndex - 2
                                && $this->isHoistedScalarConstFetchImmediatelyBeforeCall(
                                    $cfgChildren[$callIndex - 1] ?? null
                                )
                            )
                        )
                    ) {
                        // get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5) — lone hoisted bitmask (#16152, #11804).
                        // htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) — bitmask before trailing bool (#11407).
                        array_unshift($producers, $child);
                        continue;
                    } elseif (
                        $i === $callIndex - 2
                        && $this->isHoistedScalarConstFetchImmediatelyBeforeCall(
                            $cfgChildren[$callIndex - 1] ?? null
                        )
                    ) {
                        // f($x + 1, …, true) / var_export(1.0+0.0, true) — arith feeds earlier arg;
                        // trailing ConstFetch is bool/null (#19515, #17210).
                        array_unshift($producers, $child);
                        continue;
                    }
                }
                break;
            }
            // php-cfg PropertyFetch/ArrayDimFetch prelude before Isset_ — not a sibling call-arg producer (#15646).
            $next = $cfgChildren[$i + 1] ?? null;
            if (
                $child instanceof Op\Expr\PropertyFetch
                && $this->isPropertyFetchOnlyIssetVar($child, $next)
            ) {
                continue;
            }
            // $a->b?->m() / $a?->b?->m() — receiver fetch feeds NullsafeMethodCall, not outer call arg (#22753).
            // Without this, byIndex maps var_export($h->b?->f(), true) arg0 to the Recv object.
            if (
                ($child instanceof Op\Expr\PropertyFetch || $child instanceof Op\Expr\NullsafePropertyFetch)
                && $next instanceof Op\Expr\NullsafeMethodCall
                && null !== $child->result
                && (
                    $next->var === $child->result
                    || $this->operandsReferToSameVariable($next->var, $child->result)
                )
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\ArrayDimFetch
                && $this->isArrayDimFetchOnlyIssetVar($child, $next)
            ) {
                continue;
            }
            if (
                $child instanceof Op\Expr\StaticPropertyFetch
                && $this->isStaticPropertyFetchOnlyIssetVar($child, $next)
            ) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                $prevProducer = $cfgChildren[$i - 1] ?? null;
                if (
                    ($prevProducer instanceof Op\Expr\FuncCall || $prevProducer instanceof Op\Expr\NsFuncCall)
                    && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                        $prevProducer,
                        $callOp,
                        $i - 1,
                        $callIndex,
                        $cfgChildren
                    )
                ) {
                    // flock(fopen(...), LOCK_EX) — ConstFetch feeds consumer arg, not inner callee (#9611).
                    // in_array('x', g(), true) — same hoisted ConstFetch must stay in producers (#13507).
                    array_unshift($producers, $child);
                    continue;
                }
                if (
                    $child instanceof Op\Expr\ConstFetch
                    && $i === $callIndex - 1
                    && 'var_export' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
                ) {
                    $scalarName = $this->staticNameFromOperand($child->name);
                    if (
                        null !== $scalarName
                        && \in_array(strtolower($scalarName), ['true', 'false', 'null'], true)
                        && ($prevProducer instanceof Op\Expr\MethodCall || $prevProducer instanceof Op\Expr\StaticCall
                            || $prevProducer instanceof Op\Expr\FuncCall || $prevProducer instanceof Op\Expr\NsFuncCall)
                        && $this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                            $prevProducer,
                            $callOp,
                            $i - 1,
                            $callIndex,
                            $cfgChildren
                        )
                    ) {
                        // var_export($it->current(), true) — trailing return flag, not a producer row (#13901, #17251).
                        continue;
                    }
                }
                if (
                    $child instanceof Op\Expr\ConstFetch
                    && $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg($child, $i, $callIndex, $cfgChildren)
                ) {
                    // var_export(array_keys($a, null), true) — null feeds nested callee arg (#11272).
                    continue;
                }
            }
            if ($this->isComparisonInlineCallArgProducer($child)) {
                if ($this->inlineCallArgProducerFeedsConsumer($child, $callOp)) {
                    array_unshift($producers, $child);
                    continue;
                }
                if (
                    $i === $callIndex - 2
                    && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($cfgChildren[$callIndex - 1] ?? null)
                    && 'var_export' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
                ) {
                    // var_export($x !== false, true) — comparison feeds arg #0, not trailing return flag (#17250).
                    array_unshift($producers, $child);
                    continue;
                }
                $hasDeadInlineCompareArg = false;
                if (\is_array($callOp->args ?? null)) {
                    foreach ($callOp->args as $compareCallArg) {
                        if ($this->callArgIsDeadInlineTemporary($compareCallArg)) {
                            $hasDeadInlineCompareArg = true;
                            break;
                        }
                    }
                }
                if ($hasDeadInlineCompareArg) {
                    // extendedArgvInt(..., 0 !== $a, 0 !== $b) — hoisted !== siblings (#17259).
                    array_unshift($producers, $child);
                    continue;
                }
                // explode(PATH_SEPARATOR, …) after `if (':' !== PATH_SEPARATOR || …)` — compare bool must not bind arg #0 (#15833).
                continue;
            }
            // var_export(require_once $f[, true]) — adjacent Include_/Eval_ feeds dead-temp arg (#25852, #21938).
            // Statement-level require_once earlier must not keep walking into getmypid()/file_put_contents().
            if ($child instanceof Op\Expr\Include_ || $child instanceof Op\Expr\Eval_) {
                if ($this->inlineCallArgProducerFeedsConsumer($child, $callOp)) {
                    array_unshift($producers, $child);
                    break;
                }
                $adjacentInclude = $i === $callIndex - 1
                    || (
                        $i === $callIndex - 2
                        && $this->isHoistedScalarConstFetchImmediatelyBeforeCall(
                            $cfgChildren[$callIndex - 1] ?? null
                        )
                    );
                if ($adjacentInclude) {
                    array_unshift($producers, $child);
                }
                break;
            }
            array_unshift($producers, $child);
        }

        $producers = $this->prependLeadingCallbackFirstInlineProducer($producers, $cfgChildren, $callOp);

        if ($callOp instanceof Op\Expr\MethodCall || $callOp instanceof Op\Expr\NullsafeMethodCall) {
            $producers = array_values(array_filter(
                $producers,
                fn (Op\Expr $producer): bool => !(
                    $producer instanceof Op\Expr\ArrayDimFetch
                    && $this->arrayDimFetchFeedsMethodCallReceiver($producer, $callOp->var)
                )
            ));
        }

        // Drop nested dim-fetch preludes for isset()/empty() so call args bind the bool (#21991).
        $producers = array_values(array_filter(
            $producers,
            function (Op\Expr $producer) use ($cfgChildren): bool {
                if (!$producer instanceof Op\Expr\ArrayDimFetch) {
                    return true;
                }
                $index = $this->cfgCallOpIndexInChildren($cfgChildren, $producer);
                if (false === $index) {
                    return true;
                }

                return !$this->isIssetOrEmptyInlineCallArgPreludeStmt($producer, (int) $index, $cfgChildren);
            }
        ));

        $result = $this->filterDeadVoidStatementMethodCallProducers($producers, $callOp, $cfgChildren);
        $this->precedingInlineCallArgProducersCache[$cacheKey] = $result;

        return $result;
    }

}
