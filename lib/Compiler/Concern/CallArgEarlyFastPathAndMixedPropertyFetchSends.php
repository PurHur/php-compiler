<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;

/**
 * Early call-arg ARG_SEND fast paths + mixed MethodCall/PropertyFetch wiring (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Covers literal / exactHoisted / named-CV / New_ ClassConst / New_ Closure /
 * StaticPropertyFetch siblings / #19719 mixed call+PropertyFetch producers.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 *
 * `$sends` is by-ref: exactHoistedCallArgProducerSlot appends prelude CONST_FETCH
 * ops into the send list before ARG_SEND (#23354).
 */
trait CallArgEarlyFastPathAndMixedPropertyFetchSends
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (exactHoisted may append preludes)
     * @return OpCode|null ARG_SEND when an early path matched; null to continue heuristics
     */
    private function tryCompileCallArgEarlyFastPathAndMixedPropertyFetchSend(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        ?Operand $cfgArg,
        mixed $nameSlot,
        mixed $unpackFlag,
        array &$sends
    ): ?OpCode {
        // Fast path: plain Operand\Literal args on FuncCall/NsFuncCall (str_pad(..., 5) /
        // implode(",", …)). Without this, every literal still walked the full heuristic
        // gauntlet and re-scanned growing opCodes — nested stmt blocks stayed super-linear
        // (#36387). Skip null literals (soft-null / ConstFetch prelude paths) and skip New_/
        // MethodCall sites — their slot layouts are asserted positionally (#19731).
        if (
            null === $unpackFlag
            && null !== $cfgCallOp
            && (
                $cfgCallOp instanceof Op\Expr\FuncCall
                || $cfgCallOp instanceof Op\Expr\NsFuncCall
            )
        ) {
            $literalArg = $cfgArg instanceof Operand\Literal
                ? $cfgArg
                : ($arg instanceof Operand\Literal ? $arg : null);
            if (
                $literalArg instanceof Operand\Literal
                && null !== $literalArg->value
                && null === Block::resolveVariableName($literalArg)
            ) {
                $literalSlot = $this->compileOperand($literalArg, $block, true);
                if (null !== $literalSlot) {
                    return new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $literalSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                }
            }
        }
        // Fast path: dead-temp arg whose sole writer is a hoisted FuncCall — exact php-cfg
        // link (#23354). Skip the heuristic gauntlet for nested call stmts (#36387).
        // Restricted to FuncCall/NsFuncCall producers so Array_/ternary/spread overrides
        // that run after exactHoisted at the bottom of this loop still apply.
        if (
            null === $unpackFlag
            && null !== $cfgCallOp
            && (
                $cfgCallOp instanceof Op\Expr\FuncCall
                || $cfgCallOp instanceof Op\Expr\NsFuncCall
            )
        ) {
            $exactFastSlot = $this->exactHoistedCallArgProducerSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $exactFastSlot) {
                $exactFastArg = $cfgCallOp->args[(int) $argIndex] ?? null;
                $exactFastProducer = (
                    $exactFastArg instanceof Operand
                    && \is_array($exactFastArg->ops ?? null)
                    && 1 === \count($exactFastArg->ops)
                ) ? $exactFastArg->ops[0] : null;
                if (
                    $exactFastProducer instanceof Op\Expr\FuncCall
                    || $exactFastProducer instanceof Op\Expr\NsFuncCall
                    || $exactFastProducer instanceof Op\Expr\MethodCall
                    || $exactFastProducer instanceof Op\Expr\StaticCall
                    || $exactFastProducer instanceof Op\Expr\ConstFetch
                    || $exactFastProducer instanceof Op\Expr\ClassConstFetch
                    || $exactFastProducer instanceof Op\Expr\Array_
                ) {
                    return new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $exactFastSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                }
            }
        }
        // #31720: `fn() => new C($x, null)` — named CV / Phi auto-captures must not be
        // consumed by trailing ConstFetch null/true/false folding (tryFold / prelude matchers).
        if (
            null === $unpackFlag
            && null !== $cfgCallOp
            && \is_array($cfgCallOp->args ?? null)
            && $this->callHasTrailingHoistedBoolNullConstFetch($cfgCallOp, $block)
        ) {
            $namedProbe = $cfgArg instanceof Operand ? $cfgArg : $arg;
            if ($namedProbe instanceof Operand && null !== Block::resolveVariableName($namedProbe)) {
                $namedSlot = $this->compileOperand($namedProbe, $block, true);
                if (null !== $namedSlot) {
                    return new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $namedSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                }
            }
        }
        // new C(..., Class::CONST) — fold ClassConstFetch onto a fresh constant slot before
        // dead-temp / echo-?: matchers steal a merge phi ("0"/"1") (#22576, #5506).
        if (
            null === $unpackFlag
            && $cfgCallOp instanceof Op\Expr\New_
            && null !== $block->orig
        ) {
            $foldedNewConstSlot = $this->slotForFoldedClassConstFetchNewArg(
                $cfgCallOp,
                (int) $argIndex,
                $block
            );
            if (null !== $foldedNewConstSlot) {
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $foldedNewConstSlot,
                    $nameSlot,
                    $unpackFlag
                );
            }
        }
        // new Outer(new Inner(...), fn() => …) — wire by php-cfg arg ops before legacy
        // positional New_ matchers steal the inner New_ for the Closure arg (#19771).
        if (
            null === $unpackFlag
            && $cfgCallOp instanceof Op\Expr\New_
            && null !== $block->orig
            && \is_array($cfgCallOp->args)
            && \count($cfgCallOp->args) >= 2
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
        ) {
            $ctorCallArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
            if ($this->callArgOpsContainInlineClosure($ctorCallArg)) {
                $closureSlot = $this->resolveInlineClosureCallArgSlot(
                    $ctorCallArg,
                    $block,
                    $cfgCallOp,
                    $calleeName
                );
                if (null === $closureSlot) {
                    $closureSlot = $this->resolvePrecedingClosureCallArgSlot(
                        $cfgCallOp,
                        (int) $argIndex,
                        $block,
                        $calleeName
                    );
                }
                if (null !== $closureSlot) {
                    return new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $closureSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                }
            }
        }
        // #34997: A::inc(); A::inc(); var_dump(A::$n, B::$n) — StaticPropertyFetch siblings
        // cover dead-temp args; stmt-level StaticCalls must not steal ARG_SEND (zend_compile.c).
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && null === $unpackFlag
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && \is_array($cfgCallOp->args)
            && \count($cfgCallOp->args) >= 2
        ) {
            $staticPropDeadTempCount = 0;
            foreach ($cfgCallOp->args as $staticPropArg) {
                if (
                    $this->callArgIsDeadInlineTemporary($staticPropArg)
                    && !$this->isEmbeddedCallLiteralArg($staticPropArg)
                ) {
                    ++$staticPropDeadTempCount;
                }
            }
            if ($staticPropDeadTempCount >= 2) {
                $staticPropProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $staticPropFetches = [];
                foreach ($staticPropProducers as $staticPropProducer) {
                    if ($staticPropProducer instanceof Op\Expr\StaticPropertyFetch) {
                        $staticPropFetches[] = $staticPropProducer;
                    }
                }
                if (\count($staticPropFetches) >= $staticPropDeadTempCount) {
                    $deadOrdinal = 0;
                    foreach ($cfgCallOp->args as $i => $ordArg) {
                        if (
                            !$this->callArgIsDeadInlineTemporary($ordArg)
                            || $this->isEmbeddedCallLiteralArg($ordArg)
                        ) {
                            continue;
                        }
                        if ((int) $i === (int) $argIndex) {
                            break;
                        }
                        ++$deadOrdinal;
                    }
                    $fetchProducer = $staticPropFetches[$deadOrdinal] ?? null;
                    if ($fetchProducer instanceof Op\Expr\StaticPropertyFetch) {
                        $fetchSlot = $block->slotForOperand($fetchProducer->result);
                        if (null === $fetchSlot) {
                            foreach ($this->compileExpr($fetchProducer, $block) as $op) {
                                $block->addOpCode($op);
                            }
                            $fetchSlot = $block->slotForOperand($fetchProducer->result);
                        }
                        if (null !== $fetchSlot) {
                            return new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $fetchSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                        }
                    }
                }
            }
        }
        // #19719: MethodCall/FuncCall + trailing PropertyFetch call args (insertBefore(
        // $d->createElement('x'), $r->lastChild)) — wire via producer match before
        // legacy immediate-PropertyFetch paths clobber every dead-temp arg.
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && null === $unpackFlag
            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            && \is_array($cfgCallOp->args)
            && \count($cfgCallOp->args) >= 2
        ) {
            $mixedDeadTempCount = 0;
            foreach ($cfgCallOp->args as $mixedArg) {
                if (
                    $this->callArgIsDeadInlineTemporary($mixedArg)
                    && !$this->isEmbeddedCallLiteralArg($mixedArg)
                ) {
                    ++$mixedDeadTempCount;
                }
            }
            if ($mixedDeadTempCount >= 2) {
                $mixedProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $hasCallProducer = false;
                $hasPropertyProducer = false;
                foreach ($mixedProducers as $mixedProducer) {
                    if (
                        $mixedProducer instanceof Op\Expr\MethodCall
                        || $mixedProducer instanceof Op\Expr\FuncCall
                        || $mixedProducer instanceof Op\Expr\NsFuncCall
                        || $mixedProducer instanceof Op\Expr\StaticCall
                    ) {
                        $hasCallProducer = true;
                    }
                    if (
                        $mixedProducer instanceof Op\Expr\PropertyFetch
                        || $mixedProducer instanceof Op\Expr\NullsafePropertyFetch
                    ) {
                        $hasPropertyProducer = true;
                    }
                }
                if ($hasCallProducer && $hasPropertyProducer) {
                    // Build ordered producers: non-void calls + leaf PropertyFetches only
                    // (skip chain intermediates and stmt-level void MethodCalls like loadXML).
                    $rawFetches = [];
                    foreach ($mixedProducers as $mixedProducer) {
                        if (
                            $mixedProducer instanceof Op\Expr\PropertyFetch
                            || $mixedProducer instanceof Op\Expr\NullsafePropertyFetch
                        ) {
                            $rawFetches[] = $mixedProducer;
                        }
                    }
                    // Drop PropertyFetches that feed a later PropertyFetch receiver (chains).
                    $leafFetches = [];
                    foreach ($rawFetches as $fi => $fetch) {
                        $feedsNearer = false;
                        // precedingInlineCallArgProducers returns oldest-first; chain
                        // intermediates appear before their leaf. A fetch feeds a later fetch
                        // when a later fetch's var equals this result.
                        for ($fj = $fi + 1, $fn = \count($rawFetches); $fj < $fn; ++$fj) {
                            $later = $rawFetches[$fj];
                            if (
                                null !== $fetch->result
                                && property_exists($later, 'var')
                                && null !== $later->var
                                && $this->operandsReferToSameVariable($fetch->result, $later->var)
                            ) {
                                $feedsNearer = true;
                                break;
                            }
                        }
                        // Also skip MethodCall receiver: PropertyFetch whose result is the
                        // insertBefore receiver (cfgCallOp->var).
                        if (
                            !$feedsNearer
                            && $cfgCallOp instanceof Op\Expr\MethodCall
                            && null !== $cfgCallOp->var
                            && null !== $fetch->result
                            && $this->operandsReferToSameVariable($cfgCallOp->var, $fetch->result)
                        ) {
                            $feedsNearer = true;
                        }
                        // Skip PropertyFetch that is only the receiver of a MethodCall producer
                        // in this list (documentElement before createElement on $d) (#19719).
                        if (!$feedsNearer) {
                            foreach ($mixedProducers as $maybeCall) {
                                if (
                                    $maybeCall instanceof Op\Expr\MethodCall
                                    && null !== $maybeCall->var
                                    && null !== $fetch->result
                                    && $this->operandsReferToSameVariable($maybeCall->var, $fetch->result)
                                ) {
                                    $feedsNearer = true;
                                    break;
                                }
                            }
                        }
                        if (!$feedsNearer) {
                            $leafFetches[] = $fetch;
                        }
                    }
                    // Merge call producers + leaf fetches in original CFG producer order.
                    $orderedMixed = [];
                    foreach ($mixedProducers as $mixedProducer) {
                        if (
                            $mixedProducer instanceof Op\Expr\MethodCall
                            || $mixedProducer instanceof Op\Expr\FuncCall
                            || $mixedProducer instanceof Op\Expr\NsFuncCall
                            || $mixedProducer instanceof Op\Expr\StaticCall
                        ) {
                            if ($mixedProducer instanceof Op\Expr\MethodCall) {
                                // Skip loadXML-style prior stmts; keep trailing item()/unknown
                                // producers inside the dead-arg window (#19719, #21171, #21182).
                                // Bare $d->documentElement->replaceChild(createElement, item)
                                // mixes a PropertyFetch receiver with MethodCall arg producers —
                                // the blunt !suppliesCallArgValue skip dropped item().
                                $mixedProducerIndex = array_search(
                                    $mixedProducer,
                                    $block->orig->children,
                                    true
                                );
                                $mixedConsumerIndex = array_search(
                                    $cfgCallOp,
                                    $block->orig->children,
                                    true
                                );
                                if (
                                    !\is_int($mixedProducerIndex)
                                    || !\is_int($mixedConsumerIndex)
                                    || $this->methodCallIsSkippedHoistedSiblingProducer(
                                        $mixedProducer,
                                        $mixedProducerIndex,
                                        $mixedConsumerIndex,
                                        $mixedDeadTempCount,
                                        $block->orig->children
                                    )
                                ) {
                                    continue;
                                }
                                // $d->appendChild($d->createElement('root')); importNode($src->documentElement, true)
                                // — typed appendChild/createElement are prior statements, not importNode
                                // args; ordinal matching would bind deep to documentElement (#24571, re-#18860).
                                //
                                // Do not treat every empty-usages MethodCall as statement-level: php-cfg
                                // also marks dead-temp *inline* args that way, e.g.
                                // replaceChild(createElement(...), getElementsByTagName(...)->item(0))
                                // (#25563). Keep those when a later call in the window still has live
                                // usages (getElementsByTagName → item), or when the producer sits in the
                                // trailing dead-temp arg window (item itself).
                                if (
                                    property_exists($mixedProducer, 'result')
                                    && (
                                        null === $mixedProducer->result
                                        || empty($mixedProducer->result->usages)
                                    )
                                    && $this->mixedCallArgProducerIsStatementLevelEmptyUsages(
                                        $mixedProducerIndex,
                                        $mixedConsumerIndex,
                                        $mixedDeadTempCount,
                                        $block->orig->children
                                    )
                                ) {
                                    continue;
                                }
                                $feedsLaterCallProducer = false;
                                foreach ($mixedProducers as $laterProducer) {
                                    if ($laterProducer === $mixedProducer) {
                                        continue;
                                    }
                                    if (
                                        !(
                                            $laterProducer instanceof Op\Expr\MethodCall
                                            || $laterProducer instanceof Op\Expr\FuncCall
                                            || $laterProducer instanceof Op\Expr\NsFuncCall
                                            || $laterProducer instanceof Op\Expr\StaticCall
                                        )
                                    ) {
                                        continue;
                                    }
                                    $laterIndex = array_search(
                                        $laterProducer,
                                        $block->orig->children,
                                        true
                                    );
                                    if (
                                        !\is_int($laterIndex)
                                        || $laterIndex <= $mixedProducerIndex
                                    ) {
                                        continue;
                                    }
                                    if (
                                        null !== $mixedProducer->result
                                        && $this->cfgExprUsesOperand(
                                            $laterProducer,
                                            $mixedProducer->result
                                        )
                                    ) {
                                        $feedsLaterCallProducer = true;
                                        break;
                                    }
                                }
                                if ($feedsLaterCallProducer) {
                                    continue;
                                }
                            }
                            $orderedMixed[] = $mixedProducer;
                            continue;
                        }
                        foreach ($leafFetches as $leaf) {
                            if ($leaf === $mixedProducer) {
                                $orderedMixed[] = $mixedProducer;
                                break;
                            }
                        }
                    }
                    // PropertyFetch + ConstFetch only (prior MethodCalls filtered) — fall through
                    // to propertyFetchPreludeMatchingCallArg / bool ConstFetch folding (#24571).
                    $orderedMixedHasCallProducer = false;
                    foreach ($orderedMixed as $orderedProducer) {
                        if (
                            $orderedProducer instanceof Op\Expr\MethodCall
                            || $orderedProducer instanceof Op\Expr\FuncCall
                            || $orderedProducer instanceof Op\Expr\NsFuncCall
                            || $orderedProducer instanceof Op\Expr\StaticCall
                        ) {
                            $orderedMixedHasCallProducer = true;
                            break;
                        }
                    }
                    if (!$orderedMixedHasCallProducer) {
                        // leave mixedMatched unset; outer paths wire PropertyFetch + true
                    } else {
                    $mixedMatched = null;
                    if (\count($orderedMixed) === $mixedDeadTempCount) {
                        $mixedMatched = $orderedMixed[(int) $argIndex] ?? null;
                    } else {
                        $mixedMatched = $this->matchInlineCallArgProducer(
                            $orderedMixed,
                            $cfgCallOp->args,
                            (int) $argIndex,
                            $cfgCallOp,
                            $block,
                            $calleeName
                        );
                    }
                    if ($mixedMatched instanceof Op\Expr) {
                        $mixedSlot = $this->slotForInlineCallArgProducerResult(
                            $block,
                            $mixedMatched,
                            $cfgCallOp,
                            $block->orig->children
                        ) ?? $block->slotForOperand($mixedMatched->result);
                        if (null === $mixedSlot) {
                            // Already-lowered statement MethodCall (unused appendChild(createElement)
                            // before importNode — #34405) — do not compileExpr again or the tree is
                            // mutated twice. Match THIS producer ordinal's INIT→EXEC_RETURN, not
                            // merely the method name (second createElement in the same block was
                            // skipped and both replaceChild ARG_SENDs bound item() — #34436).
                            if (
                                $mixedMatched instanceof Op\Expr\MethodCall
                                && (null === $mixedMatched->result || empty($mixedMatched->result->usages))
                                && null !== $this->slotForMethodOrStaticCallInitFollowingExecReturn(
                                    $block,
                                    $mixedMatched
                                )
                            ) {
                                $mixedMatched = null;
                            } else {
                                foreach ($this->compileExpr($mixedMatched, $block) as $op) {
                                    $block->addOpCode($op);
                                }
                                $mixedSlot = $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $mixedMatched,
                                    $cfgCallOp,
                                    $block->orig->children
                                ) ?? $block->slotForOperand($mixedMatched->result);
                            }
                        }
                        if (null !== $mixedSlot) {
                            return new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $mixedSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                        }
                    }
                    } // orderedMixedHasCallProducer
                }
            }
        }

        return null;
    }
}
