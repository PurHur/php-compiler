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

    /**
     * One O(n) pass over cfg block children indexes every op — avoids ~80 independent
     * O(n) linear scans per compileCallArgSends (#36224).
     *
     * Indexes by real array keys so sparse php-cfg children lists (holes after stmt
     * rewrites) do not poison {@see cfgCallOpIndexCache} with dense 0..count-1 slots
     * that later TypeError on `$children[$i]` (#36387 FinalClassConstCheck).
     *
     * @param list<Op> $cfgChildren
     */
    private function ensureCfgChildrenOpIndicesBuilt(array $cfgChildren, ?CfgBlock $cfgBlock = null): void
    {
        if ([] === $cfgChildren) {
            return;
        }
        $first = $cfgChildren[array_key_first($cfgChildren)];
        $mapKey = null !== $cfgBlock
            ? (string) spl_object_id($cfgBlock)
            : 'c_' . spl_object_id($first);
        $count = \count($cfgChildren);
        $prev = $this->cfgChildrenOpIndexBuiltCount[$mapKey] ?? 0;
        if ($prev === $count) {
            return;
        }
        foreach ($cfgChildren as $i => $child) {
            if ($child instanceof Op) {
                $this->cfgCallOpIndexCache[spl_object_id($child)] = $i;
            }
        }
        $this->cfgChildrenOpIndexBuiltCount[$mapKey] = $count;
    }

    /**
     * @param list<Op> $cfgChildren
     */
    private function cfgCallOpIndexInChildren(array $cfgChildren, Op $callOp, ?CfgBlock $cfgBlock = null): ?int
    {
        $this->ensureCfgChildrenOpIndicesBuilt($cfgChildren, $cfgBlock);

        return $this->cfgCallOpIndexCache[spl_object_id($callOp)] ?? null;
    }

    /**
     * array_map(intval(...), str_split(...)) — php-cfg emits callback before nested haystack FuncCalls (#15487).
     *
     * @param list<Op\Expr> $producers
     * @param list<Op>       $cfgChildren
     *
     * @return list<Op\Expr>
     */
    private function prependLeadingCallbackFirstInlineProducer(
        array $producers,
        array $cfgChildren,
        Op $callOp
    ): array {
        if (!($callOp instanceof Op\Expr\FuncCall || $callOp instanceof Op\Expr\NsFuncCall)) {
            return $producers;
        }
        $funcName = $this->resolveInlineCallArgFuncName($callOp);
        if (0 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return $producers;
        }
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\ArrowFunction
                || $producer instanceof Op\Expr\Closure
                || $producer instanceof Op\Expr\FirstClassCallable) {
                return $producers;
            }
        }
        $hasFuncCallProducer = false;
        foreach ($producers as $producer) {
            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                $hasFuncCallProducer = true;
                break;
            }
        }
        if (!$hasFuncCallProducer) {
            return $producers;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $callOp);
        if (null === $callIndex || $callIndex < 2) {
            return $producers;
        }
        $haystackFuncCallIndex = $callIndex;
        foreach ($producers as $producer) {
            if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            foreach ($cfgChildren as $i => $child) {
                if ($child === $producer && $i < $haystackFuncCallIndex) {
                    $haystackFuncCallIndex = $i;
                }
            }
        }
        $leading = null;
        $outerHaystack = $cfgChildren[$haystackFuncCallIndex] ?? null;
        for ($i = $haystackFuncCallIndex - 1; $i >= 0; --$i) {
            $candidate = $cfgChildren[$i] ?? null;
            if (!$candidate instanceof Op\Expr) {
                break;
            }
            if ($candidate instanceof Op\Expr\ArrowFunction
                || $candidate instanceof Op\Expr\Closure
                || $candidate instanceof Op\Expr\FirstClassCallable) {
                $leading = $candidate;
                break;
            }
            if ($candidate instanceof Op\Expr\FuncCall || $candidate instanceof Op\Expr\NsFuncCall) {
                $nestedInHaystack = ($outerHaystack instanceof Op\Expr\FuncCall || $outerHaystack instanceof Op\Expr\NsFuncCall)
                    && $this->isAdjacentNestedFuncCallProducer(
                        $candidate,
                        $outerHaystack,
                        $i,
                        $haystackFuncCallIndex
                    );
                if (
                    \in_array($candidate, $producers, true)
                    || $this->isNestedCallArgProducerForConsumer(
                        $candidate,
                        $callOp,
                        $i,
                        $callIndex,
                        $cfgChildren
                    )
                    || $nestedInHaystack
                ) {
                    continue;
                }

                break;
            }
            if (
                $candidate instanceof Op\Expr\ConstFetch
                && (
                    \in_array($candidate, $producers, true)
                    || $this->hoistedConstFetchFeedsNestedSiblingFuncCallArg($candidate, $i, $callIndex, $cfgChildren)
                )
            ) {
                // array_map(intval(...), str_split(str_repeat('12', 1))) — skip haystack literal prelude (#16279).
                continue;
            }
            if ($this->isInlineExprCallArgProducer($candidate)) {
                break;
            }
        }
        if (!$leading instanceof Op\Expr\ArrowFunction
            && !$leading instanceof Op\Expr\Closure
            && !$leading instanceof Op\Expr\FirstClassCallable) {
            return $producers;
        }

        array_unshift($producers, $leading);

        return $producers;
    }

    /**
     * True when an Assign's result is consumed between the assign and a later inline call (#16279).
     *
     * @param list<Op> $cfgChildren
     */
    private function assignPrecedesAndFeedsInlineCallChain(
        Op\Expr\Assign $assign,
        int $assignIndex,
        int $consumerIndex,
        array $cfgChildren
    ): bool {
        if (null === $assign->result) {
            return false;
        }
        for ($j = $assignIndex + 1; $j < $consumerIndex; ++$j) {
            $later = $cfgChildren[$j] ?? null;
            if ($later instanceof Op\Expr && $this->cfgExprUsesOperand($later, $assign->result)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_map(intval(...), f()) / array_filter(f(), is_numeric(...)) — callback before nested haystack (#15487, #15490).
     */
    private function leadingCallbackFirstInlineProducerBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if ($this->inlineClosureArrayPairCallbackArgIndex($funcName) < 0) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if (!$prev instanceof Op) {
                continue;
            }
            if ($prev instanceof Op\Expr\ArrowFunction
                || $prev instanceof Op\Expr\Closure
                || $prev instanceof Op\Expr\FirstClassCallable) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                    return null;
                }
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                continue;
            }
            if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                return null;
            }
        }

        return null;
    }

    /**
     * array_walk(new C(...), fn(...)) — inline New_ subject before trailing closure callback (#17504).
     */
    private function leadingInlineNewBeforeCallbackBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr\New_
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($this->resolveInlineCallArgFuncName($cfgCallOp))) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
        if (null === $callIndex || $callIndex < 2) {
            return null;
        }
        $callback = $block->orig->children[$callIndex - 1] ?? null;
        if (!$callback instanceof Op\Expr\Closure && !$callback instanceof Op\Expr\ArrowFunction) {
            return null;
        }
        for ($i = $callIndex - 2; $i >= 0; --$i) {
            $prev = $block->orig->children[$i];
            if ($prev instanceof Op\Expr\New_) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Array_ || $prev instanceof Op\Expr\ConstFetch) {
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * array_map(intval(...), str_split(...)) — haystack FuncCall after leading FCC/closure (#15487, #15961).
     */
    private function leadingCallbackFirstHaystackFuncCallBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if (0 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $skippedCallback = false;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i] ?? null;
            if (!$prev instanceof Op) {
                continue;
            }
            if (
                !$skippedCallback
                && ($prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\FirstClassCallable)
            ) {
                $skippedCallback = true;
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                    return null;
                }
                continue;
            }
            if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                return null;
            }
        }

        return null;
    }

    /**
     * array_filter(str_split(...), is_numeric(...)) — haystack FuncCall immediately before trailing FCC (#15490).
     *
     * Must not treat a prior sibling consumer (e.g. var_dump(...)) as the haystack when arg 0 is a
     * named CV — that wires null/void into the second expression-position call (#27344, #17989).
     */
    private function trailingInlineFuncCallHaystackBeforeCfgCall(?Op $cfgCallOp, ?Block $block): ?Op\Expr
    {
        if (null === $cfgCallOp || null === $block || null === $block->orig) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp);
        if (1 !== $this->inlineClosureArrayPairCallbackArgIndex($funcName)) {
            return null;
        }
        $haystackArg = $cfgCallOp->args[0] ?? null;
        if ($haystackArg instanceof Operand && $this->isNamedVariableOperand($haystackArg)) {
            // array_filter($b, fn|/string) — real CV haystack, not a hoisted sibling FuncCall (#27344).
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $skippedCallback = false;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $prev = $block->orig->children[$i];
            if (
                !$skippedCallback
                && ($prev instanceof Op\Expr\ArrowFunction
                    || $prev instanceof Op\Expr\Closure
                    || $prev instanceof Op\Expr\FirstClassCallable)
            ) {
                $skippedCallback = true;
                continue;
            }
            if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                // First FuncCall after skipping the callback only — inline haystack (#15490).
                // Named CV haystacks already returned null above (#27344).
                return $prev;
            }
            if ($prev instanceof Op\Expr\Assign) {
                return null;
            }
            // Array_ / other producers: haystack is not a trailing FuncCall — stop. Falling through
            // would skip past the real haystack Array_ to an older var_dump (#27344, #27347).
            return null;
        }

        return null;
    }

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
