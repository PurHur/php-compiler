<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Func;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\JIT\OperandName;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\BoundVariable;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Call-arg send compilation (#36387 / #36403).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CompileCallArgSends
{
    protected function compileCallArgSends(
        array $args,
        Block $block,
        ?string $calleeName = null,
        ?Op $cfgCallOp = null
    ): array
    {
        $this->validateCallArgOrder($args);

        if (null !== $cfgCallOp && null !== $block->orig) {
            $this->ensureCfgChildrenOpIndicesBuilt($block->orig->children, $block->orig);
        }

        if (null !== $cfgCallOp) {
            $explodeSends = $this->compileExplodeLeadingConstFetchFuncCallInlineCallArgSends($args, $block, $cfgCallOp);
            if (null !== $explodeSends) {
                return $explodeSends;
            }
            $arrayChunkSends = $this->compileArrayChunkInlineNestedCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayChunkSends) {
                return $arrayChunkSends;
            }
            $iteratorToArraySends = $this->compileIteratorToArrayInlineNewPreserveKeysCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $iteratorToArraySends) {
                return $iteratorToArraySends;
            }
            $arrayChunkEnumLengthSends = $this->compileArrayChunkInlineArrayClassConstArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayChunkEnumLengthSends) {
                return $arrayChunkEnumLengthSends;
            }
            $arrayPadSends = $this->compileArrayPadInlineHaystackCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadSends) {
                return $arrayPadSends;
            }
            $arrayPadPadTypeSends = $this->compileArrayPadInlinePadTypeEnumCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadPadTypeSends) {
                return $arrayPadPadTypeSends;
            }
            $arrayPadEnumLengthSends = $this->compileArrayPadInlineArrayClassConstLengthCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayPadEnumLengthSends) {
                return $arrayPadEnumLengthSends;
            }
            $unpackPackEnumSends = $this->compileUnpackInlinePackEnumOffsetCallArgSends($args, $block, $cfgCallOp);
            if (null !== $unpackPackEnumSends) {
                return $unpackPackEnumSends;
            }
            $extractSends = $this->compileExtractInlineMultiArgCallArgSends($args, $block, $cfgCallOp);
            if (null !== $extractSends) {
                return $extractSends;
            }
            $dateSunSends = $this->compileDateSunFuncInlineCallArgSends($args, $block, $cfgCallOp);
            if (null !== $dateSunSends) {
                return $dateSunSends;
            }
            $arrayWalkSends = $this->compileArrayWalkInlineNewClosureCallArgSends($args, $block, $cfgCallOp);
            if (null !== $arrayWalkSends) {
                return $arrayWalkSends;
            }
            $inlineClosurePairSends = $this->compileInlineClosurePairHaystackCallbackCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $inlineClosurePairSends) {
                return $inlineClosurePairSends;
            }
            $trailingComparatorAssignSends = $this->compileTrailingComparatorInlineAssignCallbackCallArgSends(
                $args,
                $block,
                $cfgCallOp
            );
            if (null !== $trailingComparatorAssignSends) {
                return $trailingComparatorAssignSends;
            }
            $this->ensureDeferredSiblingInlineCallArgProducersCompiled($block, $cfgCallOp);
        }

        $inlineProducerCfgChildren = $this->inlineCallArgProducerCfgChildren($block);

        $sends = [];
        foreach ($args as $argIndex => $arg) {
            // Zend zend_compile.c: by-ref call args cannot bind temporary lit-dim / new-prop (#29522).
            $cfgArg = null;
            if (
                null !== $cfgCallOp
                && \is_array($cfgCallOp->args ?? null)
                && \array_key_exists((int) $argIndex, $cfgCallOp->args)
            ) {
                $cfgArg = $cfgCallOp->args[(int) $argIndex];
            }
            $byRefProbe = $cfgArg instanceof Operand ? $cfgArg : $arg;
            if (
                null !== $calleeName
                && $byRefProbe instanceof Operand
                && $this->callArgRequiresByRef($calleeName, (int) $argIndex, $byRefProbe, $block)
            ) {
                $this->rejectTemporaryByRefCallArg($byRefProbe, $block, $cfgCallOp instanceof Op ? $cfgCallOp : null);
            }
            $nameSlot = $this->callArgNameSlot($arg, $block);
            $unpackFlag = $this->callArgUnpack($arg) ? 1 : null;
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
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $literalSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
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
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            $exactFastSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
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
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $namedSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
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
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $foldedNewConstSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
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
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $closureSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
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
                                $sends[] = new OpCode(
                                    OpCode::TYPE_ARG_SEND,
                                    (string) $fetchSlot,
                                    $nameSlot,
                                    $unpackFlag
                                );
                                continue;
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
                                $sends[] = new OpCode(
                                    OpCode::TYPE_ARG_SEND,
                                    (string) $mixedSlot,
                                    $nameSlot,
                                    $unpackFlag
                                );
                                continue;
                            }
                        }
                        } // orderedMixedHasCallProducer
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && $this->callArgIsNullLiteral(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
            ) {
                $nullArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($nullArg instanceof Operand) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $this->registerNullConstantSlot($block, $nullArg),
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            $mergeFamilyNamedCallArg = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                null !== $cfgCallOp
                && $mergeFamilyNamedCallArg instanceof Operand
                && null !== Block::resolveVariableName($mergeFamilyNamedCallArg)
                && !$this->callArgIsDeadInlineTemporary($mergeFamilyNamedCallArg)
                && \in_array(
                    strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $namedAssignSlot = $this->slotForNamedLocalFromAssignVarOperand($mergeFamilyNamedCallArg, $block);
                if (null !== $namedAssignSlot) {
                    $namedAssignDest = $block->slotForNamedAssignDest($mergeFamilyNamedCallArg);
                    $mergeNamedValueSlot = null !== $namedAssignDest
                        ? $this->resolveNamedAssignCallArgSlot(
                            $block,
                            (int) $namedAssignDest,
                            $calleeName,
                            (int) $argIndex,
                            $mergeFamilyNamedCallArg
                        )
                        : (string) $this->finalizeOperandSlotForAccess($block, (int) $namedAssignSlot, true);
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $mergeNamedValueSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            // PropertyFetch as any call-arg position (not only arg #0) — two('L', $el->tagName)
            // after @$doc->loadXML() must not steal the loadXML return slot (#21439, re-#16057).
            if (
                null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $matchedPrelude = $this->propertyFetchPreludeMatchingCallArg(
                        $block,
                        $cfgCallOp,
                        $callIndex,
                        (int) $argIndex,
                        $arg
                    );
                    // Bare immediate prelude only for arg #0 (legacy trim($obj->prop) path).
                    $prelude = $matchedPrelude
                        ?? (0 === (int) $argIndex ? ($block->orig->children[$callIndex - 1] ?? null) : null);
                    if (
                        $prelude instanceof Op\Expr\PropertyFetch
                        || $prelude instanceof Op\Expr\NullsafePropertyFetch
                    ) {
                        // documentElement->C14NFile($tmp) — prelude PropertyFetch is the MethodCall
                        // receiver, not arg #0; trim($obj->prop) FuncCall path unchanged (#16057).
                        $propertyFetchIsMethodReceiver = $cfgCallOp instanceof Op\Expr\MethodCall
                            && null !== $cfgCallOp->var
                            && null !== $prelude->result
                            && $this->operandsReferToSameVariable($cfgCallOp->var, $prelude->result);
                        $callArgOperand = $this->cfgCallArgOperand($cfgCallOp, (int) $argIndex, $arg);
                        $preludeFetchFeedsCallArg = null !== $prelude->result
                            && $callArgOperand instanceof Operand
                            && (
                                $this->operandsReferToSameVariable($callArgOperand, $prelude->result)
                                || (
                                    $this->callArgIsDeadInlineTemporary($arg)
                                    && $prelude === $matchedPrelude
                                )
                            );
                        if (!$propertyFetchIsMethodReceiver && $preludeFetchFeedsCallArg) {
                            if (null === $this->lastPropertyFetchResultSlotBeforePendingCall($block)) {
                                $preludeOps = $prelude instanceof Op\Expr\PropertyFetch
                                    ? $this->compileCallArgPropertyFetch(
                                        $prelude,
                                        $block,
                                        $calleeName,
                                        (int) $argIndex
                                    )
                                    : $this->compileExpr($prelude, $block);
                                foreach ($preludeOps as $op) {
                                    $block->addOpCode($op);
                                }
                            }
                            if ($prelude instanceof Op\Expr\PropertyFetch) {
                                $this->syncPropertyFetchResultToFollowingFuncCallArg($prelude, $block);
                            }
                            $propertyFetchArgSlot = $this->propertyFetchPreludeResultSlot($block, $prelude, $cfgCallOp)
                                ?? $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall($block, $prelude)
                                ?? $this->lastPropertyFetchResultSlotBeforePendingCall($block);
                            if (null !== $propertyFetchArgSlot) {
                                $sends[] = new OpCode(
                                    OpCode::TYPE_ARG_SEND,
                                    (string) $propertyFetchArgSlot,
                                    $nameSlot,
                                    $unpackFlag
                                );
                                continue;
                            }
                        }
                    }
                }
            }
            // Early inline-array ARG_SEND paths continue before the main send site — unpack must be known up front (#16151).
            if (null !== $unpackFlag && null !== $cfgCallOp && null !== $block->orig) {
                $unpackArrayProducer = $this->matchInlineArrayProducersToArrayCallArgs(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                    $cfgCallOp->args ?? [],
                    (int) $argIndex
                );
                if ($unpackArrayProducer instanceof Op\Expr\Array_) {
                    $unpackArraySlot = $block->slotForOperand($unpackArrayProducer->result);
                    if (null === $unpackArraySlot) {
                        $unpackArrayOps = $this->compileArrayLiteral($unpackArrayProducer, $block);
                        if ([] !== $unpackArrayOps) {
                            $sends = array_merge($sends, $unpackArrayOps);
                        }
                        $unpackArraySlot = $block->slotForOperand($unpackArrayProducer->result)
                            ?? $this->slotFromInitArrayLiteralOps($unpackArrayOps);
                    }
                    if (null !== $unpackArraySlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $unpackArraySlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
            }
            if (
                0 === (int) $argIndex
                && null !== $cfgCallOp
                && null !== $block->orig
                && 1 === \count($args)
                && (
                    'closure::fromcallable' === strtolower((string) $calleeName)
                    || (
                        $cfgCallOp instanceof Op\Expr\StaticCall
                        && 'closure' === strtolower((string) $this->staticNameFromOperand($cfgCallOp->class))
                        && 'fromcallable' === strtolower((string) $this->staticNameFromOperand($cfgCallOp->name))
                    )
                )
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                $leadingCallback = \is_int($callIndex) && $callIndex > 0
                    ? ($block->orig->children[$callIndex - 1] ?? null)
                    : null;
                if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                    $fccInlineArgSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                    if (null !== $fccInlineArgSlot) {
                        $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, (string) $fccInlineArgSlot, $nameSlot, $unpackFlag);
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
                && \in_array(
                    strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['is_array', 'count', 'array_keys'],
                    true
                )
            ) {
                $countFamilyCallArg = $cfgCallOp->args[0] ?? $arg;
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (
                    \is_int($callIndex)
                    && $callIndex > 0
                    && $countFamilyCallArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($countFamilyCallArg)
                ) {
                    $immediateProducer = $block->orig->children[$callIndex - 1] ?? null;
                    if (
                        $immediateProducer instanceof Op\Expr\FuncCall
                        || $immediateProducer instanceof Op\Expr\NsFuncCall
                    ) {
                        $producerIndex = $callIndex - 1;
                        $hoistedArrayCallSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $producerIndex,
                            $block->orig->children
                        );
                        if (null === $hoistedArrayCallSlot) {
                            $hoistedArrayCallSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                                $block,
                                $cfgCallOp,
                                0
                            );
                        }
                        if (null === $hoistedArrayCallSlot) {
                            $hoistedArrayCallSlot = $this->slotForLastInlineFuncCallExecReturn($block, $sends);
                            if (null !== $hoistedArrayCallSlot) {
                                $hoistedArrayCallSlot = (string) $hoistedArrayCallSlot;
                            }
                        }
                        if (null !== $hoistedArrayCallSlot) {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                $hoistedArrayCallSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'array_splice' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && \in_array((int) $argIndex, [1, 2], true)
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? $arg)
            ) {
                $sends[] = new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $this->compileOperand($arg, $block, true),
                    $nameSlot,
                    $unpackFlag
                );
                continue;
            }
            if (
                null !== $cfgCallOp
                && $cfgCallOp instanceof Op\Expr\MethodCall
                && null !== $block->orig
            ) {
                $inlineNewBindArgSlot = $this->slotForInlineNewClosureBindNewThisArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $inlineNewBindArgSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $inlineNewBindArgSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            if (
                null !== $cfgCallOp
                && $cfgCallOp instanceof Op\Expr\StaticCall
                && null !== $block->orig
            ) {
                $staticBindClosureSlot = $this->slotForStaticClosureBindInlineClosureArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $staticBindClosureSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $staticBindClosureSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
                $staticBindNewThisSlot = $this->slotForStaticClosureBindNewThisArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $staticBindNewThisSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $staticBindNewThisSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $deadInlinePrelude = $this->hoistedDeadInlinePreludeProducerForCallArgIndex(
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                );
                if ($deadInlinePrelude instanceof Op\Expr) {
                    if (
                        $deadInlinePrelude instanceof Op\Expr\ConstFetch
                        && $this->constFetchIsNull($deadInlinePrelude)
                    ) {
                        $preludeSlot = $this->registerNullConstantSlot(
                            $block,
                            $deadInlinePrelude->result ?? new Operand\Temporary()
                        );
                    } else {
                        $preludeSlot = $block->slotForOperand($deadInlinePrelude->result);
                        if (null === $preludeSlot) {
                            if ($deadInlinePrelude instanceof Op\Expr\ConstFetch) {
                                $preludeSlot = $this->slotForHoistedScalarConstFetchCallArg($deadInlinePrelude, $block);
                            } else {
                                foreach ($this->compileExpr($deadInlinePrelude, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $preludeSlot = $block->slotForOperand($deadInlinePrelude->result);
                            }
                        }
                    }
                    if (null !== $preludeSlot) {
                        $callArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        if (
                            $callArg instanceof Operand
                            && $this->callArgOperandExpectsArrayProducer($callArg)
                            && (
                                $deadInlinePrelude instanceof Op\Expr\ConstFetch
                                || $deadInlinePrelude instanceof Op\Expr\ClassConstFetch
                            )
                        ) {
                            // in_array(..., get_declared_classes(), true) — haystack must not steal strict prelude (#16540).
                        } elseif (
                            $deadInlinePrelude instanceof Op\Expr\FuncCall
                            || $deadInlinePrelude instanceof Op\Expr\NsFuncCall
                            || $deadInlinePrelude instanceof Op\Expr\StaticCall
                            || $deadInlinePrelude instanceof Op\Expr\MethodCall
                        ) {
                            // Array haystack nested FuncCall — EXEC_RETURN wiring in haystack-family resolution (#16540).
                        } else {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $preludeSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $preludeProducer = $this->hoistedPreludeProducerForCallArgIndex($cfgCallOp, (int) $argIndex, $block);
                if ($preludeProducer instanceof Op\Expr\ConstFetch) {
                    $constName = $this->staticNameFromOperand($preludeProducer->name);
                    if (null !== $constName && 'null' === strtolower($constName)) {
                        $callArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        if (
                            $callArg instanceof Operand
                            && $this->callArgOperandExpectsArrayProducer($callArg)
                        ) {
                            // array_column([...], null, 'x') — haystack must not steal trailing null prelude (#15914, #16324).
                        } elseif ($this->arraySpliceUnaryOffsetReplacementUsesDedicatedProducerWiring($cfgCallOp, (int) $argIndex, $block)) {
                            // array_splice($a, -N, $len, null) — offset is UnaryMinus, not hoisted null (#16328).
                        } elseif ($this->mbstringUnaryOffsetNullLengthUsesDedicatedProducerWiring($cfgCallOp, (int) $argIndex, $block)) {
                            // mb_substr($s, -N, null) — offset is UnaryMinus, not hoisted null (#16481).
                        } elseif (
                            !$this->callArgIsNullLiteral(
                                $callArg,
                                $cfgCallOp,
                                (int) $argIndex,
                                $block
                            )
                        ) {
                            // array_reduce([...], fn, null) — callback dead temp must not steal
                            // trailing null $initial prelude (#23571).
                        } else {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $this->registerNullConstantSlot(
                                    $block,
                                    $preludeProducer->result ?? new Operand\Temporary()
                                ),
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    } elseif (
                        ($preludeProducer = $this->hoistedConstPreludeProducerForCallArgIndex(
                            $cfgCallOp,
                            (int) $argIndex,
                            $block
                        )) instanceof Op\Expr\ConstFetch
                    ) {
                        $constSlot = $this->slotForHoistedScalarConstFetchCallArg($preludeProducer, $block);
                        if (null !== $constSlot) {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $constSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    }
                } elseif (
                    ($preludeProducer = $this->hoistedConstPreludeProducerForCallArgIndex(
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )) instanceof Op\Expr\ClassConstFetch
                ) {
                    $callArgForClassConstPrelude = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    if (
                        $callArgForClassConstPrelude instanceof Operand
                        && (
                            $this->callArgOpsContainConcatList($callArgForClassConstPrelude)
                            || (
                                $cfgCallOp instanceof Op\Expr\New_
                                && null === $this->classConstFetchWriterForNewArg($callArgForClassConstPrelude, $block)
                            )
                        )
                    ) {
                        // Skip — ConcatList / non-ClassConst New_ arg (#22971).
                    } else {
                    $callIndexForEnumPrelude = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    $enumFeedsTrailingArgOnly = \is_int($callIndexForEnumPrelude)
                        && 0 === (int) $argIndex
                        && null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                            $cfgCallOp,
                            $callIndexForEnumPrelude,
                            $block->orig->children
                        );
                    if (!$enumFeedsTrailingArgOnly) {
                        $classConstSlot = $block->slotForOperand($preludeProducer->result);
                        if (null === $classConstSlot) {
                            foreach ($this->compileExpr($preludeProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $classConstSlot = $block->slotForOperand($preludeProducer->result);
                        }
                        if (null !== $classConstSlot) {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $classConstSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $immediatePrelude = $block->orig->children[$callIndex - 1] ?? null;
                    if ($immediatePrelude instanceof Op\Expr\ClassConstFetch) {
                        $callArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        // new T("x$v", Class::CONST) — immediate ClassConstFetch feeds arg1, not arg0 (#22971).
                        if (
                            $callArg instanceof Operand
                            && (
                                $this->callArgOpsContainConcatList($callArg)
                                || (
                                    $cfgCallOp instanceof Op\Expr\New_
                                    && null === $this->classConstFetchWriterForNewArg($callArg, $block)
                                )
                            )
                        ) {
                            // Fall through — ConcatList / non-const arg0 must not steal the prelude.
                        } else {
                        $enumFeedsTrailingArgOnly = $callArg instanceof Operand
                            && $this->callArgIsDeadInlineTemporary($callArg)
                            && null !== $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                                $cfgCallOp,
                                $callIndex,
                                $block->orig->children
                            );
                        if (
                            !$enumFeedsTrailingArgOnly
                            && $callArg instanceof Operand
                            && $this->callArgIsDeadInlineTemporary($callArg)
                            && !(
                                $cfgCallOp instanceof Op\Expr\New_
                                && 0 === (int) $argIndex
                                && $this->callArgOperandExpectsArrayProducer($callArg)
                            )
                        ) {
                            $folded = $this->tryFoldClassConstFetchDefault($immediatePrelude, $block, true);
                            if (null !== $folded) {
                                $constSlot = $block->registerConstant($immediatePrelude->result, $folded);
                                $sends[] = new OpCode(
                                    OpCode::TYPE_ARG_SEND,
                                    $constSlot,
                                    $nameSlot,
                                    $unpackFlag
                                );
                                continue;
                            }
                            $classConstSlot = $block->slotForOperand($immediatePrelude->result);
                            if (null === $classConstSlot) {
                                foreach ($this->compileExpr($immediatePrelude, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $classConstSlot = $block->slotForOperand($immediatePrelude->result);
                            }
                            if (null !== $classConstSlot) {
                                $sends[] = new OpCode(
                                    OpCode::TYPE_ARG_SEND,
                                    $classConstSlot,
                                    $nameSlot,
                                    $unpackFlag
                                );
                                continue;
                            }
                        }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && $this->callArgIsNullLiteral(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
            ) {
                $nullArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($nullArg instanceof Operand) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $this->registerNullConstantSlot($block, $nullArg),
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $hoistedIssetEmptyArgSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $hoistedIssetEmptyArgSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $hoistedIssetEmptyArgSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
                $inlineLiteralDimArgSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $inlineLiteralDimArgSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $inlineLiteralDimArgSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            $inlineArrayLiteralArgWired = false;
            $outerMultiArraySetOpArgWired = false;
            if (
                null !== $cfgCallOp
                && $cfgCallOp instanceof Op\Expr\MethodCall
                && null !== $block->orig
            ) {
                $inlineNewEnumArgSlot = $this->slotForInlineNewMethodCallEnumCaseArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $inlineNewEnumArgSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $inlineNewEnumArgSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            $castArgZeroSlot = $this->resolveHoistedCastInlineCallArgZeroSlot(
                $block,
                $cfgCallOp,
                $calleeName,
                (int) $argIndex
            );
            if (null !== $castArgZeroSlot) {
                $callArg = $args[$argIndex] ?? null;
                if ($callArg instanceof Operand && null !== Block::resolveVariableName($callArg)) {
                    $castArgZeroSlot = null;
                }
            }
            if (null !== $castArgZeroSlot) {
                $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $castArgZeroSlot, $nameSlot, $unpackFlag);
                continue;
            }
            $errorSuppressArgSlot = $this->errorSuppressEndBlockInnerResultSlotForCallArg(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $errorSuppressArgSlot) {
                $sends[] = new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    (string) $errorSuppressArgSlot,
                    $nameSlot,
                    $unpackFlag
                );
                continue;
            }
            if (
                null !== $cfgCallOp
                && $cfgCallOp instanceof Op\Expr\New_
                && 0 === (int) $argIndex
                && $this->callArgOperandExpectsArrayProducer($arg)
                && !$this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
            ) {
                $ctorArrayPrelude = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                if ($ctorArrayPrelude instanceof Op\Expr\Array_) {
                    $ctorArraySlot = $block->slotForOperand($ctorArrayPrelude->result)
                        ?? $this->slotForRecentInitArrayCallArg($block);
                    if (null !== $ctorArraySlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $ctorArraySlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'array_keys' === $this->resolveCfgFuncCallName($cfgCallOp)
                && 0 === (int) $argIndex
                && !$this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, (int) $argIndex)
            ) {
                // array_diff_assoc(array_keys(...), array_keys(...)) — stmt-before Array_, not last INIT_ARRAY (#15959, re-#13779).
                // ['a','b'] === array_keys($a) — stmt-before Array_ feeds Identical, not array_keys arg (#16056).
                // array_keys(f()[k] ?? []) — ?? RHS [] must not steal INIT_ARRAY ordinal (#16127, re-#16435).
                $keysArrayProducer = null;
                if (
                    $this->callArgIsDeadInlineTemporary($arg)
                    && $this->callArgOperandExpectsArrayProducer($arg)
                    && !$this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, 0)
                ) {
                    $keysArrayProducer = $this->inlineArrayProducerForArrayKeysDeadCallArg(
                        $arg,
                        $block,
                        $cfgCallOp
                    );
                    if (!$keysArrayProducer instanceof Op\Expr\Array_) {
                        $keysArrayProducer = $this->findInlineArrayProducerForCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                    }
                } elseif (
                    !$keysArrayProducer instanceof Op\Expr\Array_
                    && null !== ($immediateKeysArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block))
                    && null !== $immediateKeysArray->result
                    && (
                        $this->operandsReferToSameVariable($immediateKeysArray->result, $arg)
                        || (
                            $this->callArgIsDeadInlineTemporary($arg)
                            && $this->callArgOperandExpectsArrayProducer($arg)
                        )
                    )
                ) {
                    $keysArrayProducer = $immediateKeysArray;
                }
                if ($keysArrayProducer instanceof Op\Expr\Array_) {
                    $keysArrayOrdinal = $this->inlineArrayKeysHoistedArrayOrdinal($block, $cfgCallOp);
                    $keysArraySlot = null;
                    if (null !== $keysArrayOrdinal) {
                        $keysCfgChildren = $this->inlineCallArgProducerCfgChildren($block);
                        if ([] === $keysCfgChildren && null !== $block->orig) {
                            $keysCfgChildren = $block->orig->children;
                        }
                        $keysCallIndex = null;
                        foreach ($keysCfgChildren as $ki => $kchild) {
                            if ($kchild === $cfgCallOp) {
                                $keysCallIndex = $ki;
                                break;
                            }
                        }
                        $ordinalOffset = is_int($keysCallIndex)
                            ? $this->initArrayOrdinalOffsetBeforeTrailingComparatorStmt($keysCallIndex, $keysCfgChildren)
                            : 0;
                        $keysArraySlot = $this->slotForInitArrayOrdinal(
                            $block,
                            $keysArrayOrdinal + $ordinalOffset,
                            $sends
                        );
                        if (null === $keysArraySlot && $ordinalOffset > 0) {
                            $keysArraySlot = $this->slotForRecentInitArrayCallArg($block);
                        }
                    }
                    if (null === $keysArraySlot) {
                        $keysArraySlot = $this->slotForInitArrayProducerBeforeCfgCall(
                            $block,
                            $cfgCallOp,
                            $keysArrayProducer,
                            $sends
                        );
                    }
                    if (null === $keysArraySlot) {
                        foreach ($this->compileArrayLiteral($keysArrayProducer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $keysArraySlot = $this->slotForInitArrayProducerBeforeCfgCall(
                            $block,
                            $cfgCallOp,
                            $keysArrayProducer,
                            $sends
                        );
                    }
                    if (null !== $keysArraySlot) {
                        $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $keysArraySlot, $nameSlot, $unpackFlag);
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
                && 0 === (int) $argIndex
            ) {
                // preg_replace_callback_array(['/pat/' => fn(...)], $subj) — pattern map is arg #0, not hoisted closure (#9072).
                $initArraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block);
                if (null !== $initArraySlot) {
                    $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $initArraySlot, $nameSlot, $unpackFlag);
                    continue;
                }
                $patternMapArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, 0, $block);
                if ($patternMapArray instanceof Op\Expr\Array_) {
                    $patternMapSlot = $block->slotForOperand($patternMapArray->result);
                    if (null === $patternMapSlot) {
                        foreach ($this->compileArrayLiteral($patternMapArray, $block) as $op) {
                            $sends[] = $op;
                        }
                        $patternMapSlot = $block->slotForOperand($patternMapArray->result);
                    }
                    if (null !== $patternMapSlot) {
                        $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $patternMapSlot, $nameSlot, $unpackFlag);
                        continue;
                    }
                    $initArraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block);
                    if (null !== $initArraySlot) {
                        $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $initArraySlot, $nameSlot, $unpackFlag);
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'proc_open' === $this->resolveCfgFuncCallName($cfgCallOp)
                && \in_array((int) $argIndex, [0, 1, 4], true)
            ) {
                $procOpenMappedSlot = $this->resolveProcOpenInlineCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $procOpenMappedSlot) {
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        $procOpenMappedSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
                $procOpenArray = null;
                if (0 === (int) $argIndex) {
                    $procOpenArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, 0, $block);
                    if (!$procOpenArray instanceof Op\Expr\Array_) {
                        $commandArg = $cfgCallOp->args[0] ?? $arg;
                        if ($commandArg instanceof Operand) {
                            $procOpenArray = $this->unwrapArrayLiteralExpr($commandArg);
                        }
                    }
                }
                if (
                    !$procOpenArray instanceof Op\Expr\Array_
                    && (
                        0 === (int) $argIndex
                        || (
                            $this->callArgIsDeadInlineTemporary($arg)
                            && $this->callArgOperandExpectsArrayProducer($arg)
                        )
                    )
                ) {
                    $procOpenArray = $this->findInlineArrayProducerForCallArg(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if ($procOpenArray instanceof Op\Expr\Array_) {
                    $procOpenSlot = $block->slotForOperand($procOpenArray->result);
                    if (null === $procOpenSlot) {
                        $procOpenOps = $this->compileArrayLiteral($procOpenArray, $block);
                        if ([] !== $procOpenOps) {
                            $sends = array_merge($sends, $procOpenOps);
                        }
                        $procOpenSlot = $this->slotFromInitArrayLiteralOps($procOpenOps)
                            ?? $block->slotForOperand($procOpenArray->result);
                    }
                    if (null !== $procOpenSlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $procOpenSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($arg)
                && (
                    $this->callArgOperandExpectsArrayProducer($arg)
                    || $this->callArgIsDeadUntypedInlineArrayOfClassConst(
                        $arg,
                        $cfgCallOp,
                        $block,
                        (int) $argIndex
                    )
                )
                && !$this->shouldUseArrayProducerCallArgResolution($cfgCallOp, (int) $argIndex, $calleeName)
                && !$this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
            ) {
                // var_export/json_encode([…, $x->format(...)]) — stmt-before Array_ feeds the call arg (#10733, #16067).
                // array_map('explode', [','], ['a,b']) — map each hoisted Array_ to its arg slot (#16085, #16078 regression).
                // array_udiff_assoc(['a'=>1], ['A'=>1], 'strcasecmp') — sibling Array_ per arg, not stmt-before (#16194).
                // json_encode([E::A, E::B]) — enum-case arrays stay inferred:unknown (#19786).
                $inlineArrayProducer = null;
                if (null !== $block->orig) {
                    $arrayArgProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if ($this->producersIncludeInlineArrayUnionPlus($arrayArgProducers)) {
                        if (0 === (int) $argIndex) {
                            foreach ($arrayArgProducers as $producer) {
                                if (!$producer instanceof Op\Expr\BinaryOp\Plus || null === $producer->result) {
                                    continue;
                                }
                                $plusSlot = $block->slotForOperand($producer->result);
                                if (null === $plusSlot) {
                                    foreach ($this->compileExpr($producer, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                    $plusSlot = $block->slotForOperand($producer->result);
                                }
                                if (null !== $plusSlot) {
                                    $sends[] = new OpCode(
                                        OpCode::TYPE_ARG_SEND,
                                        (string) $plusSlot,
                                        $nameSlot,
                                        $unpackFlag
                                    );
                                    continue 2;
                                }
                            }
                        }
                    } else {
                    $siblingArrayProducers = array_values(array_filter(
                        $arrayArgProducers,
                        static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                    ));
                    if (
                        'proc_open' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                        || 'array_reduce' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                        || (
                            \count($siblingArrayProducers) >= 2
                            && !$this->arrayProducersFormNestedChain($siblingArrayProducers)
                        )
                    ) {
                        // array_reduce([...], fn, []) — arg0 is often inferred:unknown so the sole
                        // array-typed dead temp is initial []; matchInlineArrayProducersToArrayCallArgs
                        // would bind it to the *first* Array_ (input) (#5626).
                        if ('array_reduce' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')) {
                            $reduceMatched = $this->matchInlineCallArgProducer(
                                $arrayArgProducers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex,
                                $cfgCallOp,
                                $block
                            );
                            $inlineArrayProducer = $reduceMatched instanceof Op\Expr\Array_
                                ? $reduceMatched
                                : null;
                        } else {
                            $inlineArrayProducer = $this->matchInlineArrayProducersToArrayCallArgs(
                                $arrayArgProducers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex
                            );
                        }
                    }
                    }
                }
                if (
                    null !== $block->orig
                    && 'array_map' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && \count($cfgCallOp->args ?? []) >= 3
                    && (int) $argIndex >= 1
                    && null === $this->arrayMapInlineNullHaystackProducerForArgIndex(
                        $cfgCallOp,
                        $block,
                        (int) $argIndex
                    )
                ) {
                    $mapProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $inlineArrayProducer = $this->matchInlineArrayProducersToArrayCallArgs(
                        $mapProducers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex
                    );
                }
                if (!$inlineArrayProducer instanceof Op\Expr\Array_) {
                    if ('proc_open' === $this->resolveCfgFuncCallName($cfgCallOp)) {
                        $inlineArrayProducer = $this->findInlineArrayProducerForCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                    } else {
                        $inlineArrayProducer = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    }
                }
                if (
                    $inlineArrayProducer instanceof Op\Expr\Array_
                    && $this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                ) {
                    $inlineArrayProducer = null;
                }
                if (!$inlineArrayProducer instanceof Op\Expr\Array_) {
                    $inlineArrayProducer = $this->findInlineArrayProducerForCallArg(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if ($inlineArrayProducer instanceof Op\Expr\Array_) {
                    $inlineArraySlot = null;
                    if (
                        'array_reduce' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                        && $this->arrayReduceCfgCallHasMultipleInlineArrayProducers($block, $cfgCallOp)
                    ) {
                        $inlineArraySlot = $this->slotForInitArrayProducerBeforeCfgCall(
                            $block,
                            $cfgCallOp,
                            $inlineArrayProducer,
                            $sends
                        );
                    }
                    if (null === $inlineArraySlot) {
                        $inlineArraySlot = $block->slotForOperand($inlineArrayProducer->result);
                    }
                    if (null === $inlineArraySlot) {
                        $inlineArrayOps = $this->compileArrayLiteral($inlineArrayProducer, $block);
                        if ([] !== $inlineArrayOps) {
                            $sends = array_merge($sends, $inlineArrayOps);
                        }
                        $inlineArraySlot = $this->slotFromInitArrayLiteralOps($inlineArrayOps)
                            ?? $block->slotForOperand($inlineArrayProducer->result);
                    }
                    if (null !== $inlineArraySlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $inlineArraySlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'array_filter' === $this->resolveCfgFuncCallName($cfgCallOp)
                && 2 === \count($cfgCallOp->args ?? [])
            ) {
                $fccInlineArgSlot = null;
                if (0 === (int) $argIndex) {
                    $haystackProducer = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
                    if ($haystackProducer instanceof Op\Expr\FuncCall
                        || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                        $fccInlineArgSlot = $block->slotForOperand($haystackProducer->result);
                        if (null === $fccInlineArgSlot) {
                            foreach ($this->compileExpr($haystackProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $fccInlineArgSlot = $block->slotForOperand($haystackProducer->result);
                        }
                    }
                } elseif (1 === (int) $argIndex) {
                    $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                    if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                        $fccInlineArgSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                    } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                        || $leadingCallback instanceof Op\Expr\Closure) {
                        $fccInlineArgSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                    }
                }
                if (null !== $fccInlineArgSlot) {
                    $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, (string) $fccInlineArgSlot, $nameSlot, $unpackFlag);
                    continue;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'array_map' === $this->resolveCfgFuncCallName($cfgCallOp)
                && \count($cfgCallOp->args ?? []) >= 2
            ) {
                $fccInlineArgSlot = null;
                $nullCallback = $this->arrayMapNullCallbackProducerBeforeCfgCall($cfgCallOp, $block);
                if ($nullCallback instanceof Op\Expr\ConstFetch) {
                    $nullInlineProducer = 0 === (int) $argIndex
                        ? $nullCallback
                        : $this->arrayMapInlineNullHaystackProducerForArgIndex($cfgCallOp, $block, (int) $argIndex);
                    if ($nullInlineProducer instanceof Op\Expr\ConstFetch) {
                        $fccInlineArgSlot = $block->slotForOperand($nullInlineProducer->result);
                        if (null === $fccInlineArgSlot) {
                            foreach ($this->compileExpr($nullInlineProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $fccInlineArgSlot = $block->slotForOperand($nullInlineProducer->result);
                        }
                    } elseif (2 === \count($cfgCallOp->args ?? []) && 1 === (int) $argIndex) {
                        $haystackArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                        if ($haystackArray instanceof Op\Expr\Array_) {
                            $fccInlineArgSlot = $block->slotForOperand($haystackArray->result);
                            if (null === $fccInlineArgSlot) {
                                $arrayOps = $this->compileArrayLiteral($haystackArray, $block);
                                if ([] !== $arrayOps) {
                                    $sends = array_merge($sends, $arrayOps);
                                }
                                $fccInlineArgSlot = $this->slotFromInitArrayLiteralOps($arrayOps)
                                    ?? $block->slotForOperand($haystackArray->result);
                            }
                        }
                    }
                }
                if (null === $fccInlineArgSlot && 0 === (int) $argIndex) {
                    $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                    if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                        $fccInlineArgSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                    } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                        || $leadingCallback instanceof Op\Expr\Closure) {
                        $fccInlineArgSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                    }
                    if (null === $fccInlineArgSlot) {
                        $fccInlineArgSlot = $this->resolvePrecedingClosureCallArgSlot(
                            $cfgCallOp,
                            (int) $argIndex,
                            $block,
                            $this->resolveCfgFuncCallName($cfgCallOp)
                        );
                    }
                    if (null === $fccInlineArgSlot) {
                        for ($scan = \count($block->opCodes) - 1; $scan >= 0; --$scan) {
                            $scanOp = $block->opCodes[$scan];
                            if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                                $fccInlineArgSlot = (int) $scanOp->arg1;
                                break;
                            }
                        }
                    }
                } elseif (null === $fccInlineArgSlot && 1 === (int) $argIndex) {
                    // array_map(fn, $named) after sort()/var_dump() — preceding stmt FuncCall is not
                    // an inline haystack (str_split(...)); only dead temps are (#24730, #15487).
                    $haystackArgProbe = $cfgCallOp->args[1] ?? $arg;
                    if (
                        $haystackArgProbe instanceof Operand
                        && $this->callArgIsDeadInlineTemporary($haystackArgProbe)
                    ) {
                        // $data = json_decode(...); array_map('intval', $data['scores']) —
                        // prefer ArrayDimFetch over the prior FuncCall EXEC_RETURN (#36355).
                        $dimHaystackSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                            $haystackArgProbe,
                            $block,
                            $cfgCallOp,
                            1
                        );
                        if (null !== $dimHaystackSlot) {
                            $fccInlineArgSlot = (int) $dimHaystackSlot;
                        } else {
                            $haystackProducer = $this->leadingCallbackFirstHaystackFuncCallBeforeCfgCall(
                                $cfgCallOp,
                                $block
                            );
                            if ($haystackProducer instanceof Op\Expr\FuncCall
                                || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                                $fccInlineArgSlot = $block->slotForOperand($haystackProducer->result);
                                if (null === $fccInlineArgSlot) {
                                    foreach ($this->compileExpr($haystackProducer, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                    $fccInlineArgSlot = $block->slotForOperand($haystackProducer->result);
                                }
                            }
                        }
                    }
                }
                if (null !== $fccInlineArgSlot) {
                    $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, (string) $fccInlineArgSlot, $nameSlot, $unpackFlag);
                    continue;
                }
            }
            // O(1) via Block cache — was a full opCodes scan per arg (#36387).
            $callOrdinal = $block->funccallInitCount();
            $dimFetchSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            $callArgForSync = null !== $cfgCallOp
                ? (($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg)
                : $arg;
            $syncedPreludeArgSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgForSync);
            if (null !== $syncedPreludeArgSlot) {
                $sends[] = new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    (string) $syncedPreludeArgSlot,
                    $nameSlot,
                    $unpackFlag
                );
                continue;
            }
            $exprPreludeSlot = null === $dimFetchSlot
                ? $this->resolvePrecedingExpressionPreludeCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                )
                : null;
            if (null !== $exprPreludeSlot) {
                if (null !== $cfgCallOp && null !== $block->orig) {
                    $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (\is_int($callIndex) && $callIndex > 0) {
                        $prelude = $block->orig->children[$callIndex - 1] ?? null;
                        if (
                            $prelude instanceof Op\Expr\PropertyFetch
                            || $prelude instanceof Op\Expr\NullsafePropertyFetch
                        ) {
                            $opcodeSlot = $this->compiledExpressionPreludeResultSlotBeforePendingFuncCall(
                                $block,
                                $prelude
                            );
                            if (null !== $opcodeSlot) {
                                $exprPreludeSlot = (string) $opcodeSlot;
                            }
                        }
                    }
                }
                $sends[] = new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $exprPreludeSlot,
                    $nameSlot,
                    $unpackFlag
                );
                continue;
            }
            // unserialize(serialize($obj)) — adjacent hoisted serialize must feed arg #0, not stale New_ slot (#16241).
            if (
                null === $dimFetchSlot
                && null !== $cfgCallOp
                && null !== $block->orig
                && !$this->isCallArgDirectArrayDimFetch($arg)
                && $this->callArgIsDeadInlineTemporary($arg)
                && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
                && !(
                    ($this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                        || $this->hasSiblingMultiArgInlineNewProducers($block, $cfgCallOp))
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                    && null === $this->nestedFuncCallProducerBeforeTrailingConstFetchPreludes(
                        $cfgCallOp,
                        (int) ($this->cfgCallOpIndex($block, $cfgCallOp) ?? -1),
                        $block->orig->children
                    )
                )
            ) {
                $adjacentNestedProducerSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $adjacentNestedProducerSlot) {
                    // Prefer exact argument→producer link over adjacent last-EXEC_RETURN steal
                    // (openssl_decrypt(str_repeat('k'), …, str_repeat('i')) + later ?: left both
                    // args on the IV slot — #35879 / peer #23354 exactHoisted last word).
                    $exactAdjacentSlot = $this->exactHoistedCallArgProducerSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $sends
                    );
                    $sends[] = new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        null !== $exactAdjacentSlot ? $exactAdjacentSlot : $adjacentNestedProducerSlot,
                        $nameSlot,
                        $unpackFlag
                    );
                    continue;
                }
            }
            if (
                null === $dimFetchSlot
                && null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
            ) {
                $nestedNewProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $positionalNewProducers = $nestedNewProducers;
                $nestedNewArgCount = \count($cfgCallOp->args ?? $args);
                $siblingNews = $this->siblingInlineNewProducersBeforeCfgOp($block, $cfgCallOp);
                if ([] !== $siblingNews) {
                    // new LimitIterator(new ArrayIterator([...]), …) — keep Array_ prelude + inner New_ (#12916, #17575).
                    if (
                        null === $this->matchNestedNewCtorInlineNewProducer(
                            $nestedNewProducers,
                            (int) $argIndex,
                            $nestedNewArgCount,
                            $cfgCallOp->args ?? $args
                        )
                    ) {
                        $nestedNewProducers = $siblingNews;
                        $positionalNewProducers = $siblingNews;
                    }
                }
                $nestedNewProducerCount = \count($nestedNewProducers);
                $inlineNewProducer = $this->matchSiblingInlineNewCallArgProducer(
                    $nestedNewProducers,
                    $cfgCallOp->args ?? $args,
                    (int) $argIndex
                );
                if (
                    null === $inlineNewProducer
                    && 1 === \count($siblingNews)
                    && 1 === $nestedNewArgCount
                    && 0 === (int) $argIndex
                ) {
                    $singleArgCallArg = ($cfgCallOp->args ?? $args)[0] ?? null;
                    if (null !== $singleArgCallArg && $this->callArgIsDeadInlineTemporary($singleArgCallArg)) {
                        $inlineNewProducer = $siblingNews[0];
                    }
                }
                if (null === $inlineNewProducer) {
                    $inlineNewProducer = $this->matchNestedNewCtorInlineNewProducer(
                        $nestedNewProducers,
                        (int) $argIndex,
                        $nestedNewArgCount,
                        $cfgCallOp->args ?? $args
                    );
                }
                if (null === $inlineNewProducer) {
                    $inlineNewProducer = $this->matchPositionalInlineNewCallArgProducer(
                        $positionalNewProducers,
                        $cfgCallOp->args ?? $args,
                        (int) $argIndex
                    );
                }
                if (null === $inlineNewProducer) {
                    $inlineNewProducer = $this->matchTrailingInlineNewCallArgProducer(
                        $nestedNewProducers,
                        $cfgCallOp->args ?? $args,
                        (int) $argIndex
                    );
                }
                if ($inlineNewProducer instanceof Op\Expr\New_) {
                    $nestedNewCallArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    $nestedNewLocal = null !== Block::resolveVariableName($nestedNewCallArg)
                        ? (
                            $this->namedLocalCallArgSlotIfBound($nestedNewCallArg, $block, $cfgCallOp, (int) $argIndex)
                            ?? $this->slotForNamedLocalFromAssignVarOperand($nestedNewCallArg, $block)
                        )
                        : null;
                    if (null === $nestedNewLocal) {
                        $innerNewSlot = $this->slotForInlineNewProducer($block, $inlineNewProducer, $sends);
                        if (null === $innerNewSlot) {
                            foreach ($this->compileExpr($inlineNewProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $innerNewSlot = $this->slotForInlineNewProducer($block, $inlineNewProducer, $sends);
                        }
                        if (null !== $innerNewSlot) {
                            $sends[] = new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                $innerNewSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                            continue;
                        }
                    }
                }
            }
            $inlineArray = null === $dimFetchSlot
                ? $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp, (int) $argIndex)
                : null;
            if (
                null !== $unpackFlag
                && null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $unpackMatch = $this->matchInlineArrayProducersToArrayCallArgs(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                    $cfgCallOp->args ?? [],
                    (int) $argIndex
                );
                if ($unpackMatch instanceof Op\Expr\Array_) {
                    $inlineArray = $unpackMatch;
                }
            }
            // array_reduce([...], fn(...), [...]) — bind each Array_/closure ARG_SEND by producer ordinal (#5626).
            if (
                null !== $cfgCallOp
                && 'array_reduce' === $this->resolveCfgFuncCallName($cfgCallOp)
                && null !== $block->orig
                && $this->arrayReduceCfgCallHasMultipleInlineArrayProducers($block, $cfgCallOp)
            ) {
                $reduceProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $reduceMatched = $this->matchInlineCallArgProducer(
                    $reduceProducers,
                    $cfgCallOp->args ?? [],
                    (int) $argIndex,
                    $cfgCallOp,
                    $block
                );
                if ($reduceMatched instanceof Op\Expr\Array_) {
                    $reduceSlot = $this->slotForInitArrayProducerBeforeCfgCall(
                        $block,
                        $cfgCallOp,
                        $reduceMatched,
                        $sends
                    );
                    if (null !== $reduceSlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            $reduceSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
                if (
                    $reduceMatched instanceof Op\Expr\Closure
                    || $reduceMatched instanceof Op\Expr\ArrowFunction
                    || $reduceMatched instanceof Op\Expr\FirstClassCallable
                ) {
                    $closureSlot = $block->slotForOperand($reduceMatched->result);
                    if (null === $closureSlot) {
                        foreach ($this->compileExpr($reduceMatched, $block) as $op) {
                            $sends[] = $op;
                        }
                        $closureSlot = $block->slotForOperand($reduceMatched->result);
                    }
                    if (null !== $closureSlot) {
                        $sends[] = new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $closureSlot,
                            $nameSlot,
                            $unpackFlag
                        );
                        continue;
                    }
                }
            }
            if (null !== $inlineArray && null !== $cfgCallOp && null !== $block->orig) {
                $consumerCallIndex = null;
                foreach ($block->orig->children as $ci => $cfgChild) {
                    if ($cfgChild === $cfgCallOp) {
                        $consumerCallIndex = $ci;
                        break;
                    }
                }
                if (\is_int($consumerCallIndex) && $consumerCallIndex > 0) {
                    $immediatePrelude = $block->orig->children[$consumerCallIndex - 1] ?? null;
                    if ($immediatePrelude instanceof Op\Expr\Isset_ || $immediatePrelude instanceof Op\Expr\Empty_) {
                        // isset(['a'=>1]['a']) / empty([...]) — prelude owns dim semantics (#16462).
                        $inlineArray = null;
                    }
                }
            }
            if (
                null !== $inlineArray
                && null !== $cfgCallOp
                && 2 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['filter_var', 'filter_input'],
                    true
                )
            ) {
                // Flat ['flags'=>FILTER_*] defers to ConstFetch+Array_ wiring (#12326).
                // Nested ['options'=>[...]] must keep the outermost Array_ (#12007, #22772).
                $filterProducers = null !== $block->orig
                    ? $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    : [];
                $leadingConstNested = $this->splitLeadingConstFetchWithNestedArrayLiteralChain($filterProducers);
                if (null !== $leadingConstNested) {
                    [, $arrayChain] = $leadingConstNested;
                    $inlineArray = $arrayChain[\count($arrayChain) - 1];
                } else {
                    $inlineArray = null;
                }
            }
            $arrayCombineNestedFuncArg = false;
            $arrayMergeNestedFuncArg = false;
            if (null !== $cfgCallOp && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp) && null !== $block->orig) {
                $combineProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $combineMatch = $this->matchArrayCombineInlineProducers($combineProducers, (int) $argIndex);
                if ($combineMatch instanceof Op\Expr\Array_) {
                    $inlineArray = $combineMatch;
                } elseif (
                    $combineMatch instanceof Op\Expr\FuncCall
                    || $combineMatch instanceof Op\Expr\NsFuncCall
                ) {
                    // array_combine(array_keys(...), [...]) — arg #0 is nested FuncCall, not inner haystack Array_ (#15558, #16097).
                    $inlineArray = $combineMatch;
                    $arrayCombineNestedFuncArg = true;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && null === $unpackFlag
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $mergeCallArg = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
                if (
                    $mergeCallArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($mergeCallArg)
                    && $this->callArgOperandExpectsArrayProducer($mergeCallArg)
                ) {
                    $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $mergeMatch = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                        $mergeProducers,
                        (int) $argIndex,
                        \count($cfgCallOp->args ?? []),
                        $cfgCallOp->args ?? []
                    );
                    if (null === $mergeMatch) {
                        $mergeMatch = $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                            $mergeProducers,
                            (int) $argIndex
                        );
                    }
                    if ($mergeMatch instanceof Op\Expr\Array_) {
                        $inlineArray = $mergeMatch;
                    } elseif (
                        $mergeMatch instanceof Op\Expr\FuncCall
                        || $mergeMatch instanceof Op\Expr\NsFuncCall
                    ) {
                        // array_merge(array_keys(...), [...]) — arg #0 is sibling FuncCall, not trailing Array_ (#12450, #16418).
                        $inlineArray = $mergeMatch;
                        $arrayMergeNestedFuncArg = true;
                    }
                }
            }
            if (null === $inlineArray && null !== $cfgCallOp) {
                if (null === $inlineArray && 'substr_replace' === $this->resolveCfgFuncCallName($cfgCallOp)) {
                    $substrReplaceProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $substrReplaceMatch = $this->matchInlineArrayProducersToArrayCallArgs(
                        $substrReplaceProducers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex
                    );
                    if ($substrReplaceMatch instanceof Op\Expr\Array_) {
                        $inlineArray = $substrReplaceMatch;
                    }
                }
                if (null === $inlineArray && !$arrayCombineNestedFuncArg && !$arrayMergeNestedFuncArg) {
                    $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                        $callArgProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
                        $arrayCombineArgZeroSiblingFunc = false;
                        if (
                            0 === (int) $argIndex
                            && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
                            && null !== $block->orig
                        ) {
                            $combineArg0 = $this->matchArrayCombineInlineProducers(
                                $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                                0
                            );
                            $arrayCombineArgZeroSiblingFunc = $combineArg0 instanceof Op\Expr\FuncCall
                                || $combineArg0 instanceof Op\Expr\NsFuncCall;
                        }
                        if (
                            $arrayCombineArgZeroSiblingFunc
                            || (
                                $this->callArgIsDeadInlineTemporary($callArgProbe)
                                && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                                && $this->inlineArrayLiteralStmtBeforeOverriddenBySiblingCallProducer(
                                    $cfgCallOp,
                                    (int) $argIndex,
                                    $block
                                )
                            )
                        ) {
                            // array_combine(array_keys(...), [...]) — arg #0 is sibling FuncCall, not trailing Array_ (#15558, #13776).
                        } elseif (
                            0 === (int) $argIndex
                            && null !== $block->orig
                            && $this->inlineCallArgZeroFedByHoistedCastProducer($block->orig->children, $cfgCallOp)
                        ) {
                            // array_merge((object)[...], [...]) — Cast feeds arg #0 (#15858).
                        } elseif (
                            ($this->callArgIsDeadInlineTemporary($callArgProbe)
                                && $this->callArgOperandExpectsArrayProducer($callArgProbe))
                            || $this->operandsReferToSameVariable($stmtBeforeArray->result, $callArgProbe)
                            || ($this->operandsReferToSameVariable($stmtBeforeArray->result, $arg)
                                && $this->callArgOperandExpectsArrayProducer($arg))
                        ) {
                            $inlineArray = $stmtBeforeArray;
                        }
                    }
                }
            }
            $callArgOperand = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (null !== $inlineArray && null !== $cfgCallOp && null !== $block->orig) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $inlineArrayIdx = array_search($inlineArray, $producers, true);
                if (
                    false !== $inlineArrayIdx
                    && ($producers[$inlineArrayIdx + 1] ?? null) instanceof Op\Expr\New_
                ) {
                    // Inline new call arg — inner Array_ is ctor prelude, not the wired operand (#13342).
                    $inlineArray = null;
                }
            }
            if (
                null !== $inlineArray
                && null !== $callArgOperand
                && !$this->callArgOperandExpectsArrayProducer($callArgOperand)
            ) {
                // new C([...]) — php-cfg often leaves the ctor arg Temporary untyped; the
                // stmt-before Array_ is the real operand (static/param defaults) (#22390).
                // Typed null ahead of Array_ must not keep the Array_ on arg #0 (#22770).
                $keepUntypedNewCtorArray = $cfgCallOp instanceof Op\Expr\New_
                    && $this->newCtorDeadTempMayBindStmtBeforeArray(
                        $callArgOperand,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                    && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray;
                if (!$keepUntypedNewCtorArray) {
                    $producers = (null !== $cfgCallOp && null !== $block->orig)
                        ? $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                        : [];
                    $outerArray = [] !== $producers
                        ? $this->matchOutermostNestedInlineArrayProducerForArgZero(
                            $producers,
                            (int) $argIndex,
                            \count($cfgCallOp->args ?? $args),
                            \count($producers)
                        )
                        : null;
                    $inlineArray = $outerArray instanceof Op\Expr\Array_ ? $outerArray : null;
                }
            }
            if (
                null !== $inlineArray
                && null !== $cfgCallOp
                && 1 === (int) $argIndex
                && 'proc_open' === $this->resolveCfgFuncCallName($cfgCallOp)
                && null !== $block->orig
            ) {
                $procOpenProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $procOpenArrayProducers = array_values(array_filter(
                    $procOpenProducers,
                    static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                ));
                $procOpenOuter = $this->matchOutermostNestedInlineArrayProducerForCallArg(
                    $procOpenProducers,
                    $procOpenArrayProducers,
                    (int) $argIndex,
                    \count($cfgCallOp->args ?? $args)
                );
                if ($procOpenOuter instanceof Op\Expr\Array_) {
                    $inlineArray = $procOpenOuter;
                }
            }
            $prefetchOps = [];
            $assignedNamedLocal = null;
            $valueSlot = null;
            $nullLiteralCallArgSlot = null;
            if (null !== $cfgCallOp) {
                $nullLiteralArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if (
                    $nullLiteralArg instanceof Operand
                    && $this->callArgIsNullLiteral(
                        $nullLiteralArg,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                ) {
                    $nullLiteralCallArgSlot = (string) $this->registerNullConstantSlot($block, $nullLiteralArg);
                    $valueSlot = $nullLiteralCallArgSlot;
                }
            }
            $hoistedEnumPropertyCallArgSlotWired = false;
            if (null !== $cfgCallOp && !$this->isCallArgDirectArrayDimFetch($arg)) {
                $valueSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $dateSunSlot = $this->wireDateSunFuncHoistedCallArgSlot($block, $cfgCallOp, (int) $argIndex);
                if (null !== $dateSunSlot) {
                    $valueSlot = $dateSunSlot;
                }
                if (null === $valueSlot) {
                    $arraySpliceSlot = $this->wireArraySpliceUnaryOffsetReplacementCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $sends
                    );
                    if (null !== $arraySpliceSlot) {
                        $valueSlot = $arraySpliceSlot;
                    }
                }
                if (null === $valueSlot) {
                    $mbstringSlot = $this->wireMbstringUnaryOffsetNullLengthCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $sends
                    );
                    if (null !== $mbstringSlot) {
                        $valueSlot = $mbstringSlot;
                    }
                }
            }
            // E::A->name / E::A?->name in call args — wire PropertyFetch slot before enum const fold (#10286, #9684).
            if (null === $valueSlot && null !== $cfgCallOp) {
                $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall($block, $cfgCallOp);
                if (null !== $immediatePropertySlot) {
                    $valueSlot = $immediatePropertySlot;
                    $hoistedEnumPropertyCallArgSlotWired = true;
                } else {
                    $hoistedPropertyOrConstSlot = $this->slotForHoistedClassConstFetchCallArg(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $hoistedPropertyOrConstSlot) {
                        $valueSlot = $hoistedPropertyOrConstSlot;
                        $hoistedEnumPropertyCallArgSlotWired = true;
                    }
                }
            }
            if (null === $valueSlot && null !== $cfgCallOp) {
                $hoistedScalarSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                    $callArgOperand,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $hoistedScalarSlot) {
                    $valueSlot = (string) $hoistedScalarSlot;
                }
            }
            if (null === $valueSlot && null !== $cfgCallOp) {
                $hoistedConstPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $hoistedConstPreludeSlot) {
                    $valueSlot = (string) $hoistedConstPreludeSlot;
                }
            }
            $syncedCoalesceSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgOperand);
            if (null === $syncedCoalesceSlot) {
                $syncedCoalesceSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($arg);
            }
            if (null !== $syncedCoalesceSlot && null === $valueSlot) {
                $valueSlot = (string) $syncedCoalesceSlot;
            }
            if (null === $valueSlot) {
                $coalesceArgSlot = $this->compileCallArgCoalesceSlot(
                    $callArgOperand,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null === $coalesceArgSlot) {
                    $coalesceArgSlot = $this->compileCallArgCoalesceSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null !== $coalesceArgSlot) {
                    $valueSlot = (string) $coalesceArgSlot;
                }
            }
            $callArgConstRoot = $this->unwrapOperandChain($callArgOperand);
            if ($callArgConstRoot instanceof Op\Expr\ConstFetch && null === $valueSlot) {
                $foldedGlobalConst = $this->tryFoldGlobalConstFetch($callArgConstRoot);
                if (null !== $foldedGlobalConst) {
                    $valueSlot = (string) $block->registerConstant($callArgOperand, $foldedGlobalConst);
                }
            }
            if (
                null !== $dimFetchSlot
                && null === $valueSlot
                && !$this->callArgIsCoalesceMergeProducer($callArgOperand, $block, $cfgCallOp, (int) $argIndex)
                && !$this->callArgIsCoalesceMergeProducer($arg, $block, $cfgCallOp, (int) $argIndex)
            ) {
                $valueSlot = $dimFetchSlot;
            } elseif (
                null === $valueSlot
                && null !== $inlineArray
                && (
                    $inlineArray instanceof Op\Expr\FuncCall
                    || $inlineArray instanceof Op\Expr\NsFuncCall
                )
            ) {
                if (
                    0 === (int) $argIndex
                    && null !== $cfgCallOp
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                ) {
                    // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not nested keys (#13760, #16418).
                } else {
                // array_combine(array_keys(...), [...]) — sibling FuncCall, not Array_ literal (#15558, #16097).
                if (null === $block->slotForOperand($inlineArray->result)) {
                    foreach ($this->compileExpr($inlineArray, $block) as $op) {
                        $sends[] = $op;
                    }
                }
                $funcOrdinal = 0;
                if (null !== $cfgCallOp && null !== $block->orig) {
                    foreach ($this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    ) as $producer) {
                        if ($producer === $inlineArray) {
                            break;
                        }
                        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                            ++$funcOrdinal;
                        }
                    }
                }
                $valueSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
                if (null === $valueSlot) {
                    $valueSlot = $this->compileOperand($inlineArray->result, $block, true);
                }
                }
            } elseif (null !== $inlineArray) {
                if (
                    0 === (int) $argIndex
                    && null !== $cfgCallOp
                    && null !== $block->orig
                    && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                ) {
                    $mergeCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (is_int($mergeCallIndex)) {
                        for ($mai = 0; $mai < $mergeCallIndex; ++$mai) {
                            $mergeChild = $block->orig->children[$mai] ?? null;
                            if ($mergeChild instanceof Op\Expr\Array_) {
                                $inlineArray = $mergeChild;
                                break;
                            }
                        }
                    }
                }
                $callArgProbeForArray = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $callArgOperand;
                if (
                    0 === (int) $argIndex
                    && null !== $cfgCallOp
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && $inlineArray instanceof Op\Expr\Array_
                    && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                ) {
                    $leadingMergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                    if (null !== $leadingMergeSlot) {
                        $valueSlot = $leadingMergeSlot;
                        $inlineArrayLiteralArgWired = true;
                    }
                }
                if (!$inlineArrayLiteralArgWired) {
                $existingArraySlot = null;
                // array_combine([...], [...]) — sibling Array_ producers map by index; never reuse "recent" init slot (#16080, #10214).
                // array_reduce([...], fn, [...]) — same: initial [] must not steal input INIT_ARRAY (#5626).
                $arrayCombineSiblingArray = null !== $cfgCallOp
                    && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && $inlineArray instanceof Op\Expr\Array_;
                $arrayReduceSiblingArrays = null !== $cfgCallOp
                    && 'array_reduce' === $this->resolveCfgFuncCallName($cfgCallOp)
                    && $inlineArray instanceof Op\Expr\Array_
                    && null !== $block->orig
                    && $this->arrayReduceCfgCallHasMultipleInlineArrayProducers($block, $cfgCallOp);
                if (
                    $arrayCombineSiblingArray
                    || $arrayReduceSiblingArrays
                    || !$this->callArgIsDeadInlineTemporary($callArgProbeForArray)
                    || !$this->callArgOperandExpectsArrayProducer($callArgProbeForArray)
                ) {
                    if (($arrayCombineSiblingArray || $arrayReduceSiblingArrays) && null !== $cfgCallOp) {
                        // Sequential array_combine(array(...), array(...)) — operand slots from the first
                        // call must not be reused via slotForOperand (#17629, re-#16080, #10214).
                        // array_reduce([...], fn, []) — map each Array_ to its INIT_ARRAY ordinal (#5626).
                        $existingArraySlot = $this->slotForInitArrayProducerBeforeCfgCall(
                            $block,
                            $cfgCallOp,
                            $inlineArray,
                            $sends
                        );
                    } else {
                        $existingArraySlot = $block->slotForOperand($inlineArray->result);
                    }
                }
                if (
                    null === $existingArraySlot
                    && $cfgCallOp instanceof Op\Expr\New_
                    && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray
                ) {
                    $recentInitArraySlot = $this->slotForRecentInitArrayCallArg($block);
                    if (null !== $recentInitArraySlot) {
                        $existingArraySlot = (int) $recentInitArraySlot;
                    }
                }
                if (null !== $existingArraySlot) {
                    $valueSlot = (string) $existingArraySlot;
                    $inlineArrayLiteralArgWired = true;
                } else {
                    $arrayOps = $this->compileArrayLiteral($inlineArray, $block);
                    if ([] !== $arrayOps) {
                        $sends = array_merge($sends, $arrayOps);
                    }
                    $initSlot = $this->slotFromInitArrayLiteralOps($arrayOps);
                    if (
                        null === $initSlot
                        && 0 === (int) $argIndex
                        && null === $unpackFlag
                        && null !== $cfgCallOp
                        && \in_array(
                            strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                            true
                        )
                        && $inlineArray instanceof Op\Expr\Array_
                    ) {
                        $initSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                    }
                    if (
                        null === $initSlot
                        && $this->callArgIsDeadInlineTemporary($callArgProbeForArray)
                        && $this->callArgOperandExpectsArrayProducer($callArgProbeForArray)
                        && !$arrayCombineSiblingArray
                        && !$arrayReduceSiblingArrays
                    ) {
                        $initSlot = $this->slotForRecentInitArrayCallArg($block);
                    }
                    if (
                        null === $initSlot
                        && $cfgCallOp instanceof Op\Expr\New_
                        && $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) === $inlineArray
                    ) {
                        $initSlot = $this->slotForRecentInitArrayCallArg($block);
                    }
                    $valueSlot = $initSlot ?? $this->compileOperand($inlineArray->result, $block, true);
                    if (null !== $valueSlot) {
                        $inlineArrayLiteralArgWired = true;
                    }
                }
                }
            } else {
                if (null === $valueSlot) {
                    $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->tryResolveEncapsedConcatListCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
                }
                if (
                    null === $valueSlot
                    && !(
                        null !== $cfgCallOp
                        && $this->callArgIsDeadInlineHaystackFamilySlot(
                            $cfgCallOp,
                            (int) $argIndex,
                            $calleeName,
                            $arg
                        )
                    )
                ) {
                    $valueSlot = $this->tryResolveChainedConcatCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->tryResolveChainedArithmeticCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->tryResolveUnaryLiteralCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot && null !== $cfgCallOp) {
                    $assignRhsSlot = $this->resolveAdjacentAssignExprCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $assignRhsSlot) {
                        $valueSlot = $assignRhsSlot;
                    }
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->tryResolveInlineBitmaskCallArgSlot($arg, $block, $sends, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot && null !== $cfgCallOp && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                $assignVarProbe = $arg;
                if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
                    $assignVarProbe = $cfgCallOp->args[(int) $argIndex];
                }
                $assignedNamedLocal = $this->slotForNamedLocalFromAssignVarOperand($assignVarProbe, $block);
                if (null === $valueSlot && null !== $assignedNamedLocal) {
                    $namedAssignDest = $block->slotForNamedAssignDest($assignVarProbe);
                    $valueSlot = null !== $namedAssignDest
                        ? $this->resolveNamedAssignCallArgSlot(
                            $block,
                            (int) $namedAssignDest,
                            $calleeName,
                            (int) $argIndex,
                            $assignVarProbe
                        )
                        : (string) $this->finalizeOperandSlotForAccess(
                            $block,
                            $assignedNamedLocal,
                            true
                        );
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->resolveInlineFirstClassCallableCallArgSlot($arg, $block, $cfgCallOp, (int) $argIndex);
                }
                if (
                    null === $valueSlot
                    && (
                        $this->isCallArgDirectArrayDimFetch($arg)
                        || (
                            null !== $cfgCallOp
                            && $this->callArgIsDeadInlineHaystackFamilySlot(
                                $cfgCallOp,
                                (int) $argIndex,
                                $calleeName,
                                $arg
                            )
                        )
                    )
                ) {
                    $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null === $valueSlot && $this->isCallArgDirectArrayDimFetch($arg)) {
                    $fetch = $this->unwrapOperandChain($arg);
                    if ($fetch instanceof Op\Expr\ArrayDimFetch && null !== $fetch->result) {
                        if (null === $block->slotForOperand($fetch->result)) {
                            foreach ($this->compileExpr($fetch, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $fetchSlot = $block->slotForOperand($fetch->result);
                        if (null !== $fetchSlot) {
                            $valueSlot = $fetchSlot;
                        }
                    }
                }
                if (null === $valueSlot && $this->isCallArgDirectPropertyFetch($arg)) {
                    $fetch = $this->unwrapOperandChain($arg);
                    if ($fetch instanceof Op\Expr\PropertyFetch && null !== $fetch->result) {
                        if (null === $block->slotForOperand($fetch->result)) {
                            foreach (
                                $this->compileCallArgPropertyFetch(
                                    $fetch,
                                    $block,
                                    $calleeName,
                                    (int) $argIndex
                                ) as $op
                            ) {
                                $sends[] = $op;
                            }
                        }
                        $fetchSlot = $block->slotForOperand($fetch->result);
                        if (null !== $fetchSlot) {
                            $valueSlot = $fetchSlot;
                        }
                    }
                }
                if (null === $valueSlot && null !== $cfgCallOp && null !== $block->orig) {
                    if (
                        !(
                            $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                        )
                    ) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if ([] !== $producers) {
                        $matched = $this->matchInlineCallArgProducer(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex,
                            $cfgCallOp,
                            $block,
                            $calleeName
                        );
                        if ($matched instanceof Op\Expr) {
                            $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                            $matched = $this->preferEmbeddedArrayLiteralOverSiblingFuncCallMatch(
                                $matched,
                                $cfgCallOp,
                                (int) $argIndex,
                                $block,
                                $callArgProbe
                            );
                            $matched = $this->preferSiblingCallOverNestedArrayInlineMatch(
                                $matched,
                                $producers,
                                $callArgProbe
                            );
                            if (
                                ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall)
                                && (int) $argIndex > 0
                                && null !== $calleeName
                                && \in_array(strtolower($calleeName), ['array_merge', 'array_merge_recursive'], true)
                                && $this->isEmbeddedCallLiteralArg($callArgProbe)
                            ) {
                                $mergeTrailingArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                                $funcCallFeedsTrailingArg = null !== $mergeTrailingArg
                                    && (
                                        $matched->result === $mergeTrailingArg
                                        || $this->operandsReferToSameVariable($matched->result, $mergeTrailingArg)
                                    );
                                // array_merge(array_keys(...), ['b']) — trailing Array_, not leading FuncCall (#13704).
                                // array_merge(['a'=>1], array_keys(...)) — keep FuncCall when it feeds arg #1 (#13775).
                                if (!$funcCallFeedsTrailingArg) {
                                    $matched = null;
                                    foreach ($producers as $producer) {
                                        if ($producer instanceof Op\Expr\Array_) {
                                            $matched = $producer;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if (
                            ($matched instanceof Op\Expr\ConstFetch || $matched instanceof Op\Expr\ClassConstFetch)
                            && null !== $cfgCallOp
                            && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall(
                                $matched,
                                $cfgCallOp,
                                (int) $argIndex,
                                $block
                            )
                        ) {
                            $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                                $block,
                                $cfgCallOp,
                                (int) $argIndex
                            );
                            if (null !== $adjacentSlot) {
                                $valueSlot = $adjacentSlot;
                                $matched = null;
                            }
                        }
                        if ($matched instanceof Op\Expr) {
                            $callArgForMatch = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                            if (
                                $this->isComparisonInlineCallArgProducer($matched)
                                && null !== $callArgForMatch
                                && !$this->operandsReferToSameVariable($matched->result, $callArgForMatch)
                            ) {
                                $matched = null;
                                $constRoot = $this->unwrapOperandChain($callArgForMatch);
                                if ($constRoot instanceof Op\Expr\ConstFetch) {
                                    $folded = $this->tryFoldGlobalConstFetch($constRoot);
                                    if (null !== $folded) {
                                        $valueSlot = (string) $block->registerConstant($callArgForMatch, $folded);
                                    }
                                }
                            }
                            if ($matched instanceof Op\Expr) {
                                if ($matched instanceof Op\Expr\Array_) {
                                    $arrayOps = $this->compileArrayLiteral($matched, $block);
                                    if ([] !== $arrayOps) {
                                        $sends = array_merge($sends, $arrayOps);
                                    }
                                    $initSlot = $this->slotFromInitArrayLiteralOps($arrayOps);
                                    $matchedSlot = $initSlot ?? $block->slotForOperand($matched->result);
                                    if (null !== $initSlot) {
                                        $inlineArrayLiteralArgWired = true;
                                    }
                                } elseif (null === $block->slotForOperand($matched->result)) {
                                    foreach ($this->compileExpr($matched, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                    $matchedSlot = $matched instanceof Op\Expr\New_
                                        ? $this->slotForInlineNewProducer($block, $matched, $sends)
                                        : $this->slotForInlineCallArgProducerResult(
                                            $block,
                                            $matched,
                                            $cfgCallOp,
                                            null !== $block->orig ? $block->orig->children : null
                                        );
                                } else {
                                    $matchedSlot = $matched instanceof Op\Expr\New_
                                        ? ($this->slotForInlineNewProducer($block, $matched, $sends)
                                            ?? $this->slotForInlineCallArgProducerResult(
                                                $block,
                                                $matched,
                                                $cfgCallOp,
                                                null !== $block->orig ? $block->orig->children : null
                                            ))
                                        : $this->slotForInlineCallArgProducerResult(
                                            $block,
                                            $matched,
                                            $cfgCallOp,
                                            null !== $block->orig ? $block->orig->children : null
                                        );
                                }
                                if (null !== $matchedSlot) {
                                    $valueSlot = $matchedSlot;
                                }
                            }
                        }
                    }
                    }
                }
                if (null === $valueSlot && $this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileOperand($arg, $block, true);
                } elseif (null === $valueSlot) {
                    $valueSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                        $arg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
                }
                if (
                    null === $valueSlot
                    && null !== $cfgCallOp
                    && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                ) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $inlineProducerCfgChildren,
                        $cfgCallOp
                    );
                    $newProducer = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        $calleeName
                    );
                    if (
                        $newProducer instanceof Op\Expr\New_
                        && $this->inlineNewProducerFeedsCallArg(
                            $newProducer,
                            $cfgCallOp->args[(int) $argIndex] ?? $arg
                        )
                    ) {
                        if (null === $block->slotForOperand($newProducer->result)) {
                            foreach ($this->compileExpr($newProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $valueSlot = $this->slotForInlineNewProducer($block, $newProducer, $sends);
                        if (null !== $valueSlot) {
                            $block->markDeferredArrayLiteralKeepSlot((int) $valueSlot);
                        }
                    }
                    if (null === $valueSlot) {
                        $valueSlot = $this->compileOperand($arg, $block, true);
                    }
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileHoistedEmptyCallArg($arg, $block);
                }
                if (null === $valueSlot) {
                    if ($this->isEmbeddedCallLiteralArg($arg)) {
                        $valueSlot = $this->compileOperand($arg, $block, true);
                    }
                }
                if (null === $valueSlot) {
                    if (
                        null === $calleeName
                        || !$this->callArgRequiresByRef($calleeName, (int) $argIndex, $arg, $block)
                    ) {
                        $valueSlot = $this->tryFoldHoistedBoolNullLiteralCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null === $valueSlot) {
                            $valueSlot = $this->tryFoldCallArgCompileTimeValue($arg, $block, $calleeName, $cfgCallOp);
                        }
                        if (
                            null === $valueSlot
                            && null !== $cfgCallOp
                            && is_array($cfgCallOp->args ?? null)
                            && isset($cfgCallOp->args[(int) $argIndex])
                            && $cfgCallOp->args[(int) $argIndex] !== $arg
                        ) {
                            $valueSlot = $this->tryFoldCallArgCompileTimeValue(
                                $cfgCallOp->args[(int) $argIndex],
                                $block,
                                $calleeName,
                                $cfgCallOp
                            );
                        }
                    }
                }
                if (null === $valueSlot && !$this->isCallArgDirectArrayDimFetch($arg)) {
                    $valueSlot = $this->compileCallArgCoalesceSlot($arg, $block, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot) {
                    $prefetchOps = $this->compileCallArgRuntimeEnumConstFetchOps(
                        $arg,
                        $block,
                        (int) $argIndex,
                        $callOrdinal,
                        $cfgCallOp
                    );
                    if ([] !== $prefetchOps && !$this->callArgOperandIsClosureValue($arg, $block)) {
                        $skipEnumPrefetchForPropertyProducer = false;
                        if (null !== $cfgCallOp && null !== $block->orig) {
                            $prefetchProducers = $this->filterDeadClassConstFetchInlineProducers(
                                $this->precedingInlineCallArgProducersBeforeCfgOp(
                                    $block->orig->children,
                                    $cfgCallOp
                                )
                            );
                            foreach ($prefetchProducers as $prefetchProducer) {
                                if ($prefetchProducer instanceof Op\Expr\PropertyFetch
                                    || $prefetchProducer instanceof Op\Expr\NullsafePropertyFetch
                                    || $prefetchProducer instanceof Op\Expr\NullsafeMethodCall) {
                                    $skipEnumPrefetchForPropertyProducer = true;
                                    break;
                                }
                            }
                        }
                        if (!$skipEnumPrefetchForPropertyProducer) {
                            $valueSlot = $prefetchOps[0]->arg1;
                        }
                    }
                }
                if (
                    null === $valueSlot
                    && null !== $cfgCallOp
                    && null !== $block->orig
                    && !(
                        $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                        && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                        && !$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, (int) $argIndex)
                    )
                ) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if ([] !== $producers) {
                        $matched = $this->matchInlineCallArgProducer(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex,
                            $cfgCallOp,
                            $block,
                            $calleeName
                        );
                        if ($matched instanceof Op\Expr) {
                            $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                            $matched = $this->preferEmbeddedArrayLiteralOverSiblingFuncCallMatch(
                                $matched,
                                $cfgCallOp,
                                (int) $argIndex,
                                $block,
                                $callArgProbe
                            );
                            $matched = $this->preferSiblingCallOverNestedArrayInlineMatch(
                                $matched,
                                $producers,
                                $callArgProbe
                            );
                            if (
                                ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall)
                                && (int) $argIndex > 0
                                && null !== $calleeName
                                && \in_array(strtolower($calleeName), ['array_merge', 'array_merge_recursive'], true)
                                && $this->isEmbeddedCallLiteralArg($callArgProbe)
                            ) {
                                $mergeTrailingArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                                $funcCallFeedsTrailingArg = null !== $mergeTrailingArg
                                    && (
                                        $matched->result === $mergeTrailingArg
                                        || $this->operandsReferToSameVariable($matched->result, $mergeTrailingArg)
                                    );
                                if (!$funcCallFeedsTrailingArg) {
                                    $matched = null;
                                    foreach ($producers as $producer) {
                                        if ($producer instanceof Op\Expr\Array_) {
                                            $matched = $producer;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if (
                            ($matched instanceof Op\Expr\ConstFetch || $matched instanceof Op\Expr\ClassConstFetch)
                            && null !== $cfgCallOp
                            && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall(
                                $matched,
                                $cfgCallOp,
                                (int) $argIndex,
                                $block
                            )
                        ) {
                            $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                                $block,
                                $cfgCallOp,
                                (int) $argIndex
                            );
                            if (null !== $adjacentSlot) {
                                $valueSlot = $adjacentSlot;
                                $matched = null;
                            }
                        }
                        if ($matched instanceof Op\Expr) {
                            if (null === $block->slotForOperand($matched->result)) {
                                foreach ($this->compileExpr($matched, $block) as $op) {
                                    $block->addOpCode($op);
                                }
                            }
                            $matchedSlot = $matched instanceof Op\Expr\New_
                                ? ($this->slotForInlineNewProducer($block, $matched)
                                    ?? $this->slotForInlineCallArgProducerResult(
                                        $block,
                                        $matched,
                                        $cfgCallOp,
                                        $block->orig->children
                                    ))
                                : $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    $block->orig->children
                                );
                            if (null !== $matchedSlot) {
                                $valueSlot = $matchedSlot;
                            }
                        }
                    }
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->outerSiblingInlineCallArgProducerSlot($block, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot) {
                    $valueSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                }
                if (
                    null === $valueSlot
                    && null !== $cfgCallOp
                    && !(
                        null !== $block->orig
                        && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                        && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                    )
                ) {
                    $valueSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $cfgCallOp, (int) $argIndex);
                }
                if (null === $valueSlot && null !== $cfgCallOp) {
                    $valueSlot = $this->resolveAdjacentAssignExprCallArgSlot($block, $cfgCallOp, (int) $argIndex);
                }
                $closureSlot = $this->resolveInlineClosureCallArgSlot($arg, $block, $cfgCallOp, $calleeName);
                $precedingClosureSlot = null;
                if (null === $closureSlot && null !== $cfgCallOp) {
                    $precedingClosureSlot = $this->resolvePrecedingClosureCallArgSlot(
                        $cfgCallOp,
                        (int) $argIndex,
                        $block,
                        $calleeName
                    );
                    if (null !== $precedingClosureSlot) {
                        $closureSlot = $precedingClosureSlot;
                    }
                }
                if (
                    null !== $closureSlot
                    && null === $assignedNamedLocal
                    && !$this->isNamedVariableOperand($arg)
                    && !$this->callArgIsNullLiteral(
                        $cfgCallOp->args[(int) $argIndex] ?? $arg,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    )
                    && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? $arg)
                    && (
                        null !== $precedingClosureSlot
                        || $this->callArgOperandIsClosureValue($arg, $block, $calleeName)
                    )
                    && !(
                        null !== $cfgCallOp
                        && 0 === (int) $argIndex
                        && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
                        && $this->callArgOperandExpectsArrayProducer($cfgCallOp->args[(int) $argIndex] ?? $arg)
                    )
                ) {
                    $valueSlot = $closureSlot;
                }
                if (
                    null === $valueSlot
                    && 0 === $argIndex
                    && null !== $calleeName
                    && ('Closure::bind' === $calleeName || 'Closure::fromCallable' === $calleeName)
                ) {
                    for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                        $scanOp = $block->opCodes[$i];
                        if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                            break;
                        }
                        if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                            $valueSlot = $scanOp->arg1;
                            break;
                        }
                        if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                            $valueSlot = $scanOp->arg1;
                            break;
                        }
                    }
                }
                if (null === $valueSlot) {
                    if (
                        null !== $cfgCallOp
                        && null !== $block->orig
                        && $this->callArgIsDeadInlineTemporary($arg)
                    ) {
                        $immediateUnarySlot = $this->slotForImmediateUnaryHoistedCallArg(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex,
                            $calleeName
                        );
                        if (null !== $immediateUnarySlot) {
                            $valueSlot = $immediateUnarySlot;
                        }
                        if (null === $valueSlot) {
                            $fseekWhenceSlot = $this->slotForFseekWhenceHoistedCallArg(
                                $block,
                                $cfgCallOp,
                                (int) $argIndex,
                                $calleeName
                            );
                            if (null !== $fseekWhenceSlot) {
                                $valueSlot = $fseekWhenceSlot;
                            }
                        }
                    }
                    if (
                        null === $valueSlot
                        && null !== $cfgCallOp
                        && null !== $block->orig
                        && $this->callArgIsDeadInlineTemporary($arg)
                        && !(
                            $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                            && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                            && !$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $cfgCallOp, (int) $argIndex)
                        )
                    ) {
                        $valueSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                        if (null === $valueSlot) {
                            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $matched = $this->matchInlineCallArgProducer(
                                $producers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex,
                                $cfgCallOp,
                                $block,
                                $calleeName
                            );
                            if ($matched instanceof Op\Expr) {
                                if (null === $block->slotForOperand($matched->result)) {
                                    foreach ($this->compileExpr($matched, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $matchedSlot = $block->slotForOperand($matched->result);
                                if (null !== $matchedSlot) {
                                    $valueSlot = $matchedSlot;
                                }
                            }
                        }
                    }
                    if (null === $valueSlot) {
                        if (null !== $cfgCallOp && $this->callArgIsDeadInlineTemporary($arg)) {
                            $classConstSlot = $this->slotForHoistedClassConstFetchCallArg(
                                $arg,
                                $block,
                                $cfgCallOp,
                                (int) $argIndex
                            );
                            if (null !== $classConstSlot) {
                                $valueSlot = $classConstSlot;
                            }
                        }
                        if (null === $valueSlot) {
                            if (
                                null !== $cfgCallOp
                                && null !== $block->orig
                                && $this->callArgIsDeadInlineTemporary($arg)
                                && $this->callArgOperandExpectsArrayProducer($arg)
                            ) {
                                $arrayFuncSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                                    $block,
                                    $cfgCallOp,
                                    (int) $argIndex
                                );
                                if (null === $arrayFuncSlot) {
                                    $arrayProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                        $block->orig->children,
                                        $cfgCallOp
                                    );
                                    $arrayMatched = $this->matchInlineCallArgProducer(
                                        $arrayProducers,
                                        $cfgCallOp->args ?? [],
                                        (int) $argIndex,
                                        $cfgCallOp,
                                        $block,
                                        $calleeName
                                    );
                                    if (
                                        $arrayMatched instanceof Op\Expr\FuncCall
                                        || $arrayMatched instanceof Op\Expr\NsFuncCall
                                    ) {
                                        $arrayFuncSlot = $this->slotForInlineCallArgProducerResult(
                                            $block,
                                            $arrayMatched,
                                            $cfgCallOp,
                                            $block->orig->children
                                        );
                                    }
                                }
                                if (null !== $arrayFuncSlot) {
                                    $valueSlot = $arrayFuncSlot;
                                }
                            }
                            if (null === $valueSlot) {
                                $valueSlot = $this->compileOperand($arg, $block, true);
                            }
                        }
                    }
                }
                if (null === $valueSlot && $arg instanceof Operand\NullOperand) {
                    $valueSlot = $this->registerNullConstantSlot($block, $arg);
                }
                if (
                    null === $assignedNamedLocal
                    && null !== $valueSlot
                    && !$hoistedEnumPropertyCallArgSlotWired
                    && !$inlineArrayLiteralArgWired
                    && !$this->isCallArgDirectArrayDimFetch($arg)
                    && null !== $block->orig
                    && ($arg instanceof Operand\Variable || $arg instanceof Operand\Temporary)
                    && !(
                        null !== $cfgCallOp
                        && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                    )
                ) {
                    $hasProducer = false;
                    foreach ($block->orig->children as $child) {
                        if (!($child instanceof Op\Expr) || null === $child->result) {
                            continue;
                        }
                        if ($this->operandsReferToSameVariable($child->result, $arg)) {
                            $hasProducer = true;
                            break;
                        }
                    }
                    if (!$this->callArgHasPriorStmtCoalesce($arg, $block, $cfgCallOp, (int) $argIndex)) {
                        $immediateUnarySlot = null;
                        if (null !== $cfgCallOp) {
                            $immediateUnarySlot = $this->slotForImmediateUnaryHoistedCallArg(
                                $block,
                                $cfgCallOp,
                                (int) $argIndex,
                                $calleeName
                            );
                        }
                        if (null !== $immediateUnarySlot) {
                            $valueSlot = $immediateUnarySlot;
                        } else {
                        $producerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                        if (
                            null !== $producerSlot
                            && !$this->isNamedVariableOperand($arg)
                            && null === $this->namedLocalCallArgSlotIfBound($arg, $block, $cfgCallOp, (int) $argIndex)
                        ) {
                            $valueSlot = $producerSlot;
                        } elseif (null !== $cfgCallOp) {
                            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $matched = $this->matchInlineCallArgProducer(
                                $producers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex,
                                $cfgCallOp,
                                $block,
                                $calleeName
                            );
                            if ($matched instanceof Op\Expr) {
                                $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                                if (
                                    ($matched instanceof Op\Expr\MethodCall
                                        || $matched instanceof Op\Expr\FuncCall
                                        || $matched instanceof Op\Expr\NsFuncCall
                                        || $matched instanceof Op\Expr\StaticCall)
                                    && $callArgProbe instanceof Operand
                                    && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                                ) {
                                    $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                                    if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                                        $matched = $stmtBeforeArray;
                                    }
                                }
                                $matchedSlot = $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    $block->orig->children
                                ) ?? $block->slotForOperand($matched->result);
                                if (null === $matchedSlot) {
                                    foreach ($this->compileExpr($matched, $block) as $op) {
                                        $block->addOpCode($op);
                                    }
                                    $matchedSlot = $this->slotForInlineCallArgProducerResult(
                                        $block,
                                        $matched,
                                        $cfgCallOp,
                                        $block->orig->children
                                    ) ?? $block->slotForOperand($matched->result);
                                }
                                if (null !== $matchedSlot) {
                                    $valueSlot = $matchedSlot;
                                }
                            }
                        }
                        }
                    }
                }
                $evalSlot = $this->resolvePrecedingEvalCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $evalSlot) {
                    $valueSlot = $evalSlot;
                }
                $skipPreferNamedLocal = false;
                if (null !== $cfgCallOp && null !== $block->orig) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $arrayProducerCount = 0;
                    foreach ($producers as $producer) {
                        if ($producer instanceof Op\Expr\Array_) {
                            ++$arrayProducerCount;
                        }
                    }
                    if (
                        $arrayProducerCount >= 2
                        && !$this->callIncludesNamedParameter($cfgCallOp)
                        && null === $this->slotForNamedLocalFromAssignVarOperand($arg, $block)
                    ) {
                        $matched = $this->matchInlineCallArgProducer(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex,
                            $cfgCallOp,
                            $block,
                            $calleeName
                        );
                        if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
                            $matchedSlot = $this->slotForInlineCallArgProducerResult(
                                $block,
                                $matched,
                                $cfgCallOp,
                                null !== $block->orig ? $block->orig->children : null
                            );
                            if (null === $matchedSlot) {
                                foreach ($this->compileExpr($matched, $block) as $op) {
                                    $block->addOpCode($op);
                                }
                                $matchedSlot = $this->slotForInlineCallArgProducerResult(
                                    $block,
                                    $matched,
                                    $cfgCallOp,
                                    null !== $block->orig ? $block->orig->children : null
                                );
                            }
                            if (null !== $matchedSlot) {
                                $valueSlot = $matchedSlot;
                                $skipPreferNamedLocal = true;
                            }
                        }
                    }
                }
                if (!$skipPreferNamedLocal) {
                    $valueSlot = $this->preferNamedLocalCallArgSlot(
                        $arg,
                        $block,
                        $valueSlot,
                        (
                            null !== $cfgCallOp
                            && $this->callArgInlineProducerIsNew($cfgCallOp, (int) $argIndex, $block)
                        ) ? null : $calleeName
                    );
                }
            }
            if ([] !== $prefetchOps) {
                $sends = array_merge($sends, $prefetchOps);
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $namedLocalSlot = $this->namedLocalCallArgSlotIfBound($arg, $block, $cfgCallOp, (int) $argIndex);
                if (null === $namedLocalSlot && null === $assignedNamedLocal) {
                    $assignedNamedLocal = $this->slotForNamedLocalFromAssignVarOperand($arg, $block);
                }
                $callArgNamed = Block::resolveVariableName($cfgCallOp->args[(int) $argIndex] ?? $arg);
                if (
                    \count($producers) >= 2
                    && null === $namedLocalSlot
                    && null === $assignedNamedLocal
                    && (null === $callArgNamed || '' === $callArgNamed)
                ) {
                    $matched = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        $calleeName
                    );
                    if ($matched instanceof Op\Expr) {
                        $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        $matched = $this->preferSiblingCallOverNestedArrayInlineMatch(
                            $matched,
                            $producers,
                            $callArgProbe
                        );
                    }
                    if (
                        ($matched instanceof Op\Expr\ConstFetch || $matched instanceof Op\Expr\ClassConstFetch)
                        && null !== $cfgCallOp
                        && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall(
                            $matched,
                            $cfgCallOp,
                            (int) $argIndex,
                            $block
                        )
                    ) {
                        $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $adjacentSlot) {
                            $valueSlot = $adjacentSlot;
                            $matched = null;
                        }
                    }
                    if ($matched instanceof Op\Expr) {
                        if (null === $block->slotForOperand($matched->result)) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $matchedSlot = $this->slotForEmittedIssetOrEmptyProducer($block, $matched)
                            ?? (
                                $matched instanceof Op\Expr\New_
                                    ? $this->slotForInlineNewProducer($block, $matched, $sends)
                                    : $this->slotForInlineCallArgProducerResult(
                                        $block,
                                        $matched,
                                        $cfgCallOp,
                                        $block->orig->children
                                    )
                            );
                        if (null !== $matchedSlot && null === $valueSlot) {
                            $valueSlot = $matchedSlot;
                        }
                    }
                } elseif (null !== $namedLocalSlot) {
                    $valueSlot = $namedLocalSlot;
                } elseif (null !== $assignedNamedLocal) {
                    $valueSlot = (string) $assignedNamedLocal;
                }
                if ('array_column' === strtolower($calleeName ?? '')) {
                    if (0 === $argIndex) {
                        $arrayExpr = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                        if ($arrayExpr instanceof Op\Expr\Array_) {
                            if (null === $block->slotForOperand($arrayExpr->result)) {
                                foreach ($this->compileExpr($arrayExpr, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $arraySlot = $block->slotForOperand($arrayExpr->result);
                            if (null !== $arraySlot) {
                                $valueSlot = $arraySlot;
                            }
                        }
                    } elseif (1 === $argIndex || 2 === $argIndex) {
                        $nullTarget = $this->arrayColumnNullPreludeArgIndex($cfgCallOp);
                        if ($nullTarget === $argIndex) {
                            foreach ($block->orig->children as $i => $child) {
                                if ($child === $cfgCallOp) {
                                    $prev = $block->orig->children[$i - 1] ?? null;
                                    if ($prev instanceof Op\Expr\ConstFetch) {
                                        $name = $this->staticNameFromOperand($prev->name);
                                        if (null !== $name && 'null' === strtolower($name)) {
                                            if (null === $block->slotForOperand($prev->result)) {
                                                foreach ($this->compileExpr($prev, $block) as $op) {
                                                    $sends[] = $op;
                                                }
                                            }
                                            $nullSlot = $block->slotForOperand($prev->result);
                                            if (null !== $nullSlot) {
                                                $valueSlot = $nullSlot;
                                            }
                                        }
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                if (
                    \in_array(strtolower($calleeName ?? ''), ['in_array', 'array_search', 'array_key_exists'], true)
                    && null !== $block->orig
                ) {
                    $arraySearchProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if (1 === (int) $argIndex) {
                        $haystackArg = $cfgCallOp->args[1] ?? $arg;
                        if (
                            null !== $haystackArg
                            && $this->callArgIsCoalesceMergeProducer($haystackArg, $block, $cfgCallOp, 1)
                        ) {
                            $coalesceHaystackSlot = $this->compileCallArgCoalesceSlot(
                                $haystackArg,
                                $block,
                                $cfgCallOp,
                                1
                            );
                            if (null !== $coalesceHaystackSlot) {
                                $valueSlot = (string) $coalesceHaystackSlot;
                            }
                        }
                        if (
                            null === $valueSlot
                            && null !== $haystackArg
                            && $this->callArgOperandExpectsArrayProducer($haystackArg)
                        ) {
                            $haystackArrayProducer = $this->matchInlineArraySearchHaystackProducer(
                                $arraySearchProducers,
                                $haystackArg
                            );
                            if ($haystackArrayProducer instanceof Op\Expr\Array_) {
                                $haystackSlot = $block->slotForOperand($haystackArrayProducer->result);
                                if (null === $haystackSlot) {
                                    foreach ($this->compileArrayLiteral($haystackArrayProducer, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                    $haystackSlot = $block->slotForOperand($haystackArrayProducer->result);
                                }
                                if (null !== $haystackSlot) {
                                    $valueSlot = (string) $haystackSlot;
                                }
                            }
                            if (null === $valueSlot) {
                                $constFuncSplit = $this->splitLeadingConstFetchWithFuncCallCallArg($arraySearchProducers);
                                $funcHaystack = null;
                                if (null !== $constFuncSplit) {
                                    [, $funcHaystack] = $constFuncSplit;
                                } elseif (
                                    2 === \count($arraySearchProducers)
                                    && ($arraySearchProducers[0] instanceof Op\Expr\FuncCall
                                        || $arraySearchProducers[0] instanceof Op\Expr\NsFuncCall)
                                    && $arraySearchProducers[1] instanceof Op\Expr\ConstFetch
                                ) {
                                    $funcHaystack = $arraySearchProducers[0];
                                }
                                if ($funcHaystack instanceof Op\Expr) {
                                    if (null === $block->slotForOperand($funcHaystack->result)) {
                                        foreach ($this->compileExpr($funcHaystack, $block) as $op) {
                                            $sends[] = $op;
                                        }
                                    }
                                    $haystackFuncSlot = $this->slotForInlineCallArgProducerResult(
                                        $block,
                                        $funcHaystack,
                                        $cfgCallOp,
                                        $block->orig->children
                                    );
                                    if (null !== $haystackFuncSlot) {
                                        $valueSlot = (string) $haystackFuncSlot;
                                    }
                                }
                            }
                        }
                    } elseif (
                        2 === (int) $argIndex
                        && \in_array(strtolower($calleeName ?? ''), ['in_array', 'array_search'], true)
                    ) {
                        $strictArg = $cfgCallOp->args[2] ?? $arg;
                        foreach ($arraySearchProducers as $producer) {
                            if (!$producer instanceof Op\Expr\ConstFetch) {
                                continue;
                            }
                            $strictName = $this->staticNameFromOperand($producer->name);
                            if (
                                null === $strictName
                                || !\in_array(strtolower($strictName), ['true', 'false'], true)
                            ) {
                                continue;
                            }
                            if (
                                null !== $strictArg
                                && !$this->operandsReferToSameVariable($producer->result, $strictArg)
                            ) {
                                continue;
                            }
                            if (null === $block->slotForOperand($producer->result)) {
                                foreach ($this->compileExpr($producer, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $strictSlot = $block->slotForOperand($producer->result);
                            if (null !== $strictSlot) {
                                $valueSlot = (string) $strictSlot;
                            }
                            break;
                        }
                    } elseif (0 === (int) $argIndex) {
                        foreach ($block->orig->children as $i => $child) {
                            if ($child !== $cfgCallOp) {
                                continue;
                            }
                            $callArg = $cfgCallOp->args[0] ?? null;
                            for ($j = $i - 1; $j >= 0; --$j) {
                                $prev = $block->orig->children[$j] ?? null;
                                if (!$prev instanceof Op) {
                                    continue;
                                }
                                if ($prev instanceof Op\Expr\ConstFetch) {
                                    if (
                                        null !== $callArg
                                        && $this->operandsReferToSameVariable($prev->result, $callArg)
                                    ) {
                                        if (null === $block->slotForOperand($prev->result)) {
                                            foreach ($this->compileExpr($prev, $block) as $op) {
                                                $sends[] = $op;
                                            }
                                        }
                                        $needleSlot = $block->slotForOperand($prev->result);
                                        if (null !== $needleSlot) {
                                            $valueSlot = $needleSlot;
                                        }
                                        break 2;
                                    }
                                    continue;
                                }
                                if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                                    break;
                                }
                            }
                            break;
                        }
                    }
                }
                if (
                    'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? $calleeName ?? '')
                    && null !== $block->orig
                ) {
                    $padProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    if (0 === (int) $argIndex) {
                        foreach ($padProducers as $producer) {
                            if (!$producer instanceof Op\Expr\Array_) {
                                continue;
                            }
                            $padArraySlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                            if (null === $padArraySlot) {
                                $padArraySlot = $block->slotForOperand($producer->result);
                            }
                            if (null === $padArraySlot) {
                                foreach ($this->compileArrayLiteral($producer, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $padArraySlot = $this->slotForInitArrayOrdinal($block, 0, $sends)
                                    ?? $block->slotForOperand($producer->result);
                            }
                            if (null !== $padArraySlot) {
                                $valueSlot = (string) $padArraySlot;
                                // hold([]); array_pad([...], -N, 0) — do not let sibling EXEC_RETURN clobber haystack (#15421, #16066).
                                $inlineArrayLiteralArgWired = true;
                            }
                            break;
                        }
                    } elseif (1 === (int) $argIndex) {
                        foreach ($padProducers as $producer) {
                            if (
                                !$producer instanceof Op\Expr\UnaryMinus
                                && !$producer instanceof Op\Expr\UnaryPlus
                            ) {
                                continue;
                            }
                            if (null === $block->slotForOperand($producer->result)) {
                                foreach ($this->compileExpr($producer, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $lengthSlot = $block->slotForOperand($producer->result);
                            if (null !== $lengthSlot) {
                                $valueSlot = (string) $lengthSlot;
                                $inlineArrayLiteralArgWired = true;
                            }
                            break;
                        }
                    }
                }
            }
            foreach ($this->tryEmitAdjacentAssignForInlineCallArg(
                $arg,
                null !== $valueSlot ? (string) $valueSlot : null,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) as $assignOp) {
                $sends[] = $assignOp;
            }
            if (null !== $cfgCallOp) {
                $skipSiblingArrayProducer = (null !== $unpackFlag)
                    || (
                        $this->callArgIsDeadInlineTemporary($arg)
                        && $this->callArgOperandExpectsArrayProducer($arg)
                        && !$this->shouldUseArrayProducerCallArgResolution($cfgCallOp, (int) $argIndex, $calleeName)
                    );
                $skipSiblingForLeadingArrayMergeFamily = false;
                if (null !== $block->orig) {
                    $mergeName = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                    if (
                        \in_array($mergeName, ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'], true)
                    ) {
                        $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        if (null !== $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                            $mergeProducers,
                            (int) $argIndex
                        )) {
                            $skipSiblingForLeadingArrayMergeFamily = true;
                        } elseif (0 === (int) $argIndex && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)) {
                            $skipSiblingForLeadingArrayMergeFamily = true;
                        }
                    }
                }
                if (
                    null !== $cfgCallOp
                    && null !== $block->orig
                    && $this->callArgIsDeadInlineTemporary($arg)
                    && !$inlineArrayLiteralArgWired
                    && null === $dimFetchSlot
                    && null === $valueSlot
                ) {
                    $embeddedProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $embeddedTarget = $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                        $embeddedProducers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        $calleeName
                    );
                    if ($embeddedTarget instanceof Op\Expr) {
                        $foldedEmbedded = $embeddedTarget instanceof Op\Expr\ConstFetch
                            ? $this->tryFoldGlobalConstFetch($embeddedTarget)
                            : null;
                        if (null !== $foldedEmbedded) {
                            $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $foldedEmbedded);
                        } else {
                            $embeddedSlot = $block->slotForOperand($embeddedTarget->result);
                            if (null === $embeddedSlot) {
                                foreach ($this->compileExpr($embeddedTarget, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $embeddedSlot = $block->slotForOperand($embeddedTarget->result);
                            }
                            if (null !== $embeddedSlot) {
                                $valueSlot = (string) $embeddedSlot;
                            }
                        }
                    }
                }
                if (!$skipSiblingArrayProducer && !$skipSiblingForLeadingArrayMergeFamily && null === $valueSlot) {
                $siblingOps = [];
                $siblingSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $siblingOps
                );
                if (null !== $siblingSlot && !$inlineArrayLiteralArgWired && null === $dimFetchSlot) {
                    if ([] !== $siblingOps) {
                        $sends = array_merge($sends, $siblingOps);
                    }
                    $valueSlot = $siblingSlot;
                } elseif (
                    null !== $cfgCallOp
                    && $this->siblingConsumerHasTrailingByRefNamedLocal($cfgCallOp)
                    && $this->callArgIsDeadInlineTemporary($arg)
                ) {
                    // #15476 regression from #15848: operand→slot map drifts for precompiled
                    // hoisted str_repeat() producers when a trailing by-ref local follows.
                    $execReturnSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                        $block,
                        (int) $argIndex
                    );
                    if (null !== $execReturnSlot) {
                        $valueSlot = (string) $execReturnSlot;
                    }
                }
                }
                if (
                    0 === (int) $argIndex
                    && $skipSiblingForLeadingArrayMergeFamily
                    && null === $unpackFlag
                    && null === $valueSlot
                    && null !== $block->orig
                ) {
                    $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $mergeMapped = $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                        $mergeProducers,
                        (int) $argIndex
                    );
                    if (
                        $mergeMapped instanceof Op\Expr\FuncCall
                        || $mergeMapped instanceof Op\Expr\NsFuncCall
                    ) {
                        $funcOrdinal = 0;
                        foreach ($mergeProducers as $producer) {
                            if ($producer === $mergeMapped) {
                                break;
                            }
                            if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                                ++$funcOrdinal;
                            }
                        }
                        $execSlot = $this->slotForFuncCallExecReturnOrdinal($block, $funcOrdinal, $sends);
                        if (null !== $execSlot) {
                            $valueSlot = (string) $execSlot;
                        }
                    } elseif ($mergeMapped instanceof Op\Expr\Array_) {
                        $leadingInitSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                        if (null !== $leadingInitSlot) {
                            $valueSlot = $leadingInitSlot;
                            $inlineArrayLiteralArgWired = true;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $nameSlot
                && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
                && null !== $block->orig
            ) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                    $producers,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                );
                if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $arraySlot = $block->slotForOperand($matched->result);
                    if (null !== $arraySlot) {
                        $valueSlot = $arraySlot;
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $recoveredIssetEmpty = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $recoveredIssetEmpty) {
                    $valueSlot = $recoveredIssetEmpty;
                }
            }
            if (
                null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($arg)
                && null !== $block->orig
                && !$this->callArgOperandExpectsArrayProducer($arg)
            ) {
                $logicalPhi = $this->logicalShortCircuitOrPhiMergeSlot($block);
                if (null !== $logicalPhi) {
                    $valueSlot = (string) $logicalPhi;
                } else {
                    $andPhi = $this->logicalShortCircuitPhiMergeSlot($block);
                    if (null !== $andPhi) {
                        $valueSlot = (string) $andPhi;
                    } elseif (\in_array(strtolower($calleeName ?? ''), ['exit', 'die'], true)) {
                        $exitPhi = $this->resolveExitLogicalShortCircuitCallArgSlot($block);
                        if (null !== $exitPhi) {
                            $valueSlot = $exitPhi;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && $argIndex > 0
                && null !== $valueSlot
                && is_array($cfgCallOp->args ?? null)
                && isset($cfgCallOp->args[0])
            ) {
                $leadingCoalesce = $this->findCoalesceStmtForCallArg($cfgCallOp->args[0], $block);
                if (null !== $leadingCoalesce) {
                    $coalesceSlot = $this->slotForCoalesceResult($block, $leadingCoalesce);
                    if (null !== $coalesceSlot && (string) $valueSlot === (string) $coalesceSlot) {
                        $hoisted = $this->tryFoldHoistedBoolNullLiteralCallArg(
                            $arg,
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $hoisted) {
                            $valueSlot = $hoisted;
                        } elseif ($this->isCallArgUnrelatedToPriorStmtCoalesce($arg)) {
                            $direct = $this->compileOperand($arg, $block, true);
                            if (null !== $direct) {
                                $valueSlot = $direct;
                            }
                        }
                    }
                }
            }
            $namedAssignDestProbe = $arg;
            if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
                $namedAssignDestProbe = $cfgCallOp->args[(int) $argIndex];
            }
            $namedAssignDest = $block->slotForNamedAssignDest($namedAssignDestProbe);
            if (null !== $namedAssignDest) {
                $valueSlot = $this->resolveNamedAssignCallArgSlot(
                    $block,
                    (int) $namedAssignDest,
                    $calleeName,
                    (int) $argIndex,
                    $namedAssignDestProbe
                );
            } elseif (null !== $valueSlot && is_numeric($valueSlot)) {
                $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, (int) $valueSlot, true);
            }
            if (null === $namedAssignDest) {
            if (
                null !== $cfgCallOp
                && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
                && $this->countDeadArrayInlineCallArgs($cfgCallOp) >= 1
                && null !== $block->orig
                && !$this->precedingInlineCallArgHasPlusOrConcatProducer($block->orig->children, $cfgCallOp)
                && null === $this->nestedInlineFuncCallProducerForCallArg($block, $cfgCallOp, (int) $argIndex)
            ) {
                $producers = null !== $block->orig
                    ? $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    )
                    : [];
                $deadArrayArgCount = $this->countDeadArrayInlineCallArgs($cfgCallOp);
                if ($deadArrayArgCount >= 2) {
                    $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                        $producers,
                        $cfgCallOp,
                        (int) $argIndex,
                        $block
                    );
                    if ($this->inlineCallArgProducerUsesExprResultSlot($matched)) {
                        if (null === $block->slotForOperand($matched->result)) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $matchedSlot = $block->slotForOperand($matched->result);
                        if (null !== $matchedSlot) {
                            $valueSlot = (string) $matchedSlot;
                        }
                    }
                } else {
                    $lastProducer = $producers[\count($producers) - 1] ?? null;
                    $hasArrayUnionPlus = false;
                    foreach ($producers as $producer) {
                        if ($producer instanceof Op\Expr\BinaryOp\Plus) {
                            $hasArrayUnionPlus = true;
                            break;
                        }
                    }
                    // Array union arg is Plus.result, not the trailing INIT_ARRAY temp (#10490, #12763).
                    if (!$hasArrayUnionPlus && !$lastProducer instanceof Op\Expr\BinaryOp\Plus) {
                        $immediateArray = $this->inlineArrayLiteralForDeadCallArg($cfgCallOp, (int) $argIndex, $block);
                        if (
                            $immediateArray instanceof Op\Expr\Array_
                            && null !== $dimFetchSlot
                        ) {
                            // Inline literal dim-fetch feeds the call arg — not the array temp (#16462).
                        } elseif ($immediateArray instanceof Op\Expr\Array_) {
                            $immediateSlot = $block->slotForOperand($immediateArray->result);
                            if (null === $immediateSlot) {
                                foreach ($this->compileExpr($immediateArray, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $immediateSlot = $block->slotForOperand($immediateArray->result);
                            }
                            if (null !== $immediateSlot) {
                                $valueSlot = (string) $immediateSlot;
                            }
                        } else {
                            $resolvedSlot = $this->slotForDeadInlineArrayOrCallResultCallArg($block, $cfgCallOp, (int) $argIndex);
                            if (null !== $resolvedSlot) {
                                $valueSlot = $resolvedSlot;
                            }
                        }
                    }
                }
            }
            }
            if (
                null !== $cfgCallOp
                && 0 === $argIndex
                && 'preg_replace_callback_array' === $this->resolveCfgFuncCallName($cfgCallOp)
            ) {
                $initArraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block);
                if (null !== $initArraySlot) {
                    $valueSlot = $initArraySlot;
                }
            } else            if (
                null !== $cfgCallOp
                && null === $valueSlot
                && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? $arg)
                && !$this->callArgIsNullLiteral(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
                && $this->callArgOperandIsClosureValue($arg, $block, $calleeName)
                && !$this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block) instanceof Op\Expr\Array_
                && $this->inlineClosureArrayPairCallbackArgIndex($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp)) === (int) $argIndex
            ) {
                $closureSlot = $this->slotForRecentClosureCallArg($block);
                if (null !== $closureSlot) {
                    $valueSlot = $closureSlot;
                }
            }
            if (
                null === $valueSlot
                && null !== $cfgCallOp
                && $this->callArgIsNullLiteral(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
            ) {
                $nullArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($nullArg instanceof Operand) {
                    $valueSlot = $this->registerNullConstantSlot($block, $nullArg);
                }
            }
            if (null !== $cfgCallOp) {
                $pointerSlot = $this->slotForHoistedArrayPointerBuiltinCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $arg
                );
                if (null !== $pointerSlot) {
                    $valueSlot = $pointerSlot;
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $trailingComparisonProducer = null;
                $cfgCallIndex = null;
                foreach ($block->orig->children as $ci => $cfgChild) {
                    if ($cfgChild === $cfgCallOp) {
                        $cfgCallIndex = $ci;
                        break;
                    }
                }
                if (null !== $cfgCallIndex && $cfgCallIndex > 0) {
                    $immediatePrelude = $block->orig->children[$cfgCallIndex - 1] ?? null;
                    if ($this->isComparisonInlineCallArgProducer($immediatePrelude)) {
                        $trailingComparisonProducer = $immediatePrelude;
                    }
                }
                if (null === $trailingComparisonProducer) {
                    $trailingProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    foreach (array_reverse($trailingProducers) as $producer) {
                        if ($this->isComparisonInlineCallArgProducer($producer)) {
                            $trailingComparisonProducer = $producer;
                            break;
                        }
                    }
                }
                $comparisonFeedsCallArg = $this->isComparisonInlineCallArgProducer($trailingComparisonProducer);
                if (
                    0 === (int) $argIndex
                    && $comparisonFeedsCallArg
                    && $this->callArgIsDeadInlineTemporary($arg)
                    && $trailingComparisonProducer instanceof Op\Expr
                    && null !== $trailingComparisonProducer->result
                ) {
                    $comparisonSlot = $block->slotForOperand($trailingComparisonProducer->result);
                    if (null === $comparisonSlot) {
                        foreach ($this->compileExpr($trailingComparisonProducer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $comparisonSlot = $block->slotForOperand($trailingComparisonProducer->result);
                    }
                    if (null !== $comparisonSlot) {
                        $valueSlot = (string) $comparisonSlot;
                    }
                }
                if (
                    0 === $argIndex
                    && !$comparisonFeedsCallArg
                    && !$this->isEmbeddedCallLiteralArg($cfgCallOp->args[0] ?? $arg)
                    && !$this->callArgOperandExpectsArrayProducer($cfgCallOp->args[0] ?? $arg)
                    && !$this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                ) {
                    $concatChainOps = [];
                    $chainedConcatSlot = $this->tryResolveChainedConcatCallArgSlot(
                        $arg,
                        $block,
                        $concatChainOps,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $chainedConcatSlot) {
                        if ([] !== $concatChainOps) {
                            $sends = array_merge($sends, $concatChainOps);
                        }
                        $valueSlot = (string) $chainedConcatSlot;
                    } else {
                        $arithmeticChainOps = [];
                        $chainedArithmeticSlot = $this->tryResolveChainedArithmeticCallArgSlot(
                            $arg,
                            $block,
                            $arithmeticChainOps,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $chainedArithmeticSlot) {
                            if ([] !== $arithmeticChainOps) {
                                $sends = array_merge($sends, $arithmeticChainOps);
                            }
                            $valueSlot = (string) $chainedArithmeticSlot;
                        }
                    }
                    if (null === $valueSlot) {
                        $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                        $trailingProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        foreach ($trailingProducers as $producer) {
                            if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                                continue;
                            }
                            if (
                                null === $callArgProbe
                                || !$this->inlineCallArgProducerFeedsCallArgOp($producer, $cfgCallOp, $callArgProbe)
                            ) {
                                continue;
                            }
                            $subjectSlot = $block->slotForOperand($producer->result);
                            if (null !== $subjectSlot) {
                                $valueSlot = (string) $subjectSlot;
                                break;
                            }
                        }
                    }
                }
                if (null !== $cfgCallOp && 0 === (int) $argIndex) {
                    $varExportOps = [];
                    $varExportSlot = $this->slotForVarExportNestedInlineCallArg(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $varExportOps
                    );
                    if (null !== $varExportSlot && !$inlineArrayLiteralArgWired) {
                        if ([] !== $varExportOps) {
                            $sends = array_merge($sends, $varExportOps);
                        }
                        $valueSlot = (string) $varExportSlot;
                    }
                }
                if (
                    $this->callArgIsDeadInlineTemporary($arg)
                    && !$comparisonFeedsCallArg
                    && !$this->callArgOperandExpectsArrayProducer(
                        $cfgCallOp->args[(int) $argIndex] ?? $arg
                    )
                    && !(
                        null !== $cfgCallOp
                        && 'var_export' === $this->resolveCfgFuncCallName($cfgCallOp)
                        && 0 === (int) $argIndex
                    )
                    && !(
                        null !== $cfgCallOp
                        && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                    )
                ) {
                $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                foreach ($trailingProducers as $producer) {
                    if (!$producer instanceof Op\Expr\ConstFetch && !$producer instanceof Op\Expr\ClassConstFetch) {
                        continue;
                    }
                    if (!$this->operandsReferToSameVariable($producer->result, $callArgProbe)) {
                        continue;
                    }
                    if (null === $block->slotForOperand($producer->result)) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $constSlot = $block->slotForOperand($producer->result);
                    if (null !== $constSlot) {
                        $valueSlot = (string) $constSlot;
                        break;
                    }
                }
                }
            }
            if (
                null !== $cfgCallOp
                && 0 === $argIndex
                && $this->callArgIsDeadInlineTemporary($arg)
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['filter_var', 'is_a', 'is_subclass_of'],
                    true
                )
            ) {
                $nestedSubjectSlot = $this->slotForNestedSubjectExecBeforeLiteralPreludeCall($block);
                if (null !== $nestedSubjectSlot) {
                    $valueSlot = $nestedSubjectSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'json_decode' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            ) {
                if (0 === (int) $argIndex) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    foreach ($producers as $producer) {
                        if (!$producer instanceof Op\Expr\FuncCall && !$producer instanceof Op\Expr\NsFuncCall) {
                            continue;
                        }
                        $subjectSlot = $block->slotForOperand($producer->result);
                        if (null === $subjectSlot) {
                            $nestedSubjectSlot = $this->slotForNestedSubjectExecBeforeLiteralPreludeCall($block);
                            if (null !== $nestedSubjectSlot) {
                                $valueSlot = $nestedSubjectSlot;
                            }
                            break;
                        }
                        $valueSlot = (string) $subjectSlot;
                        break;
                    }
                } elseif (1 === (int) $argIndex || 3 === (int) $argIndex) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $target = $this->matchInlineCallArgProducerWithEmbeddedLiterals(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        'json_decode'
                    );
                    if ($target instanceof Op\Expr) {
                        $folded = $target instanceof Op\Expr\ConstFetch
                            ? $this->tryFoldGlobalConstFetch($target)
                            : null;
                        if (null !== $folded) {
                            $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
                        } else {
                            $slot = $block->slotForOperand($target->result);
                            if (null === $slot) {
                                foreach ($this->compileExpr($target, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $slot = $block->slotForOperand($target->result);
                            }
                            if (null !== $slot) {
                                $valueSlot = (string) $slot;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && (int) $argIndex > 0
                && null !== $calleeName
                && \in_array(strtolower($calleeName), ['array_merge', 'array_merge_recursive'], true)
            ) {
                $mergeTrailingSlot = $this->resolveArrayMergeTrailingInlineArrayCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $arg,
                    $sends
                );
                if (null !== $mergeTrailingSlot) {
                    $valueSlot = $mergeTrailingSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'array_map' === $this->resolveCfgFuncCallName($cfgCallOp)
            ) {
                if (0 === (int) $argIndex) {
                    $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $producer = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args ?? [],
                        (int) $argIndex,
                        $cfgCallOp,
                        $block,
                        'array_map'
                    );
                    if ($producer instanceof Op\Expr\ConstFetch) {
                        $slot = $block->slotForOperand($producer->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($producer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($producer->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    }
                } elseif ((int) $argIndex >= 1) {
                    $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                    if (null !== $callArgProbe && $this->callArgOperandExpectsArrayProducer($callArgProbe)) {
                        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        $producer = $this->matchInlineCallArgProducer(
                            $producers,
                            $cfgCallOp->args ?? [],
                            (int) $argIndex,
                            $cfgCallOp,
                            $block,
                            'array_map'
                        );
                        if (!$producer instanceof Op\Expr\Array_) {
                            $producer = $this->matchInlineArrayProducersToArrayCallArgs(
                                $producers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex
                            );
                        }
                        if (!$producer instanceof Op\Expr\Array_) {
                            $haystackProducer = $this->leadingCallbackFirstHaystackFuncCallBeforeCfgCall($cfgCallOp, $block);
                            if ($haystackProducer instanceof Op\Expr\FuncCall
                                || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                                $producer = $haystackProducer;
                            }
                        }
                        if ($producer instanceof Op\Expr\Array_) {
                            $slot = $block->slotForOperand($producer->result);
                            if (null === $slot) {
                                foreach ($this->compileArrayLiteral($producer, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $slot = $block->slotForOperand($producer->result);
                            }
                            if (null !== $slot) {
                                $valueSlot = (string) $slot;
                            }
                        } elseif ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
                            $slot = $block->slotForOperand($producer->result);
                            if (null === $slot) {
                                foreach ($this->compileExpr($producer, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $slot = $block->slotForOperand($producer->result);
                            }
                            if (null !== $slot) {
                                $valueSlot = (string) $slot;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'array_filter' === $this->resolveCfgFuncCallName($cfgCallOp)
            ) {
                if (0 === (int) $argIndex) {
                    $haystackProducer = $this->trailingInlineFuncCallHaystackBeforeCfgCall($cfgCallOp, $block);
                    if ($haystackProducer instanceof Op\Expr\FuncCall
                        || $haystackProducer instanceof Op\Expr\NsFuncCall) {
                        $slot = $block->slotForOperand($haystackProducer->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($haystackProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($haystackProducer->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    }
                } elseif (1 === (int) $argIndex) {
                    $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                    if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                        $fccSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                        if (null !== $fccSlot) {
                            $valueSlot = (string) $fccSlot;
                        }
                    } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                        || $leadingCallback instanceof Op\Expr\Closure) {
                        $closureSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                        if (null !== $closureSlot) {
                            $valueSlot = (string) $closureSlot;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'preg_split' === $this->resolveCfgFuncCallName($cfgCallOp)
                && ((int) $argIndex === 2 || (int) $argIndex === 3)
            ) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                $unaryProducer = null;
                $constProducer = null;
                foreach ($producers as $producer) {
                    if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                        $unaryProducer = $producer;
                    } elseif ($producer instanceof Op\Expr\ConstFetch) {
                        $constProducer = $producer;
                    }
                }
                $targetProducer = 2 === (int) $argIndex ? $unaryProducer : $constProducer;
                if ($targetProducer instanceof Op\Expr) {
                    $folded = null;
                    if ($targetProducer instanceof Op\Expr\ConstFetch) {
                        $folded = $this->tryFoldGlobalConstFetch($targetProducer);
                    } elseif (
                        $targetProducer instanceof Op\Expr\UnaryMinus
                        || $targetProducer instanceof Op\Expr\UnaryPlus
                    ) {
                        $folded = $this->tryFoldUnaryLiteralDefault($targetProducer);
                    }
                    if (null !== $folded) {
                        $valueSlot = (string) $block->registerConstant(
                            new Operand\Temporary(),
                            $folded
                        );
                    } else {
                        $slot = $block->slotForOperand($targetProducer->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($targetProducer, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($targetProducer->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    }
                }
            } elseif (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'explode' === $this->resolveCfgFuncCallName($cfgCallOp)
                && 2 === (int) $argIndex
            ) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                foreach ($producers as $producer) {
                    if (!$producer instanceof Op\Expr\UnaryMinus && !$producer instanceof Op\Expr\UnaryPlus) {
                        continue;
                    }
                    $folded = $this->tryFoldUnaryLiteralDefault($producer);
                    if (null !== $folded) {
                        $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
                        break;
                    }
                    $slot = $block->slotForOperand($producer->result);
                    if (null === $slot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                    break;
                }
            }
            $dimFetchArgSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $dimFetchArgSlot) {
                $valueSlot = $dimFetchArgSlot;
            } elseif (
                null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($arg)
                && !$this->callArgIsNullLiteral(
                    $cfgCallOp->args[(int) $argIndex] ?? $arg,
                    $cfgCallOp,
                    (int) $argIndex,
                    $block
                )
                && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
            ) {
                $adjacentArgSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null === $adjacentArgSlot) {
                    $adjacentArgSlot = $this->resolveImmediatePrecedingCallProducerArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $arg
                    );
                }
                if (null !== $adjacentArgSlot) {
                    if (
                        0 === (int) $argIndex
                        && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                    ) {
                        // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not nested keys (#13760, #16418).
                    } elseif (!$hoistedEnumPropertyCallArgSlotWired) {
                        // Keep ClassConstFetch/flag wiring (CachingIterator::FULL_CACHE — #19769).
                        $valueSlot = $adjacentArgSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            ) {
                $hoisted = [];
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (null !== $callIndex) {
                    for ($i = $callIndex - 1; $i >= 0; --$i) {
                        $child = $block->orig->children[$i];
                        if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\Array_) {
                            array_unshift($hoisted, $child);
                            continue;
                        }
                        if ($child instanceof Op\Expr\Assign) {
                            break;
                        }
                        break;
                    }
                }
                $constFetches = array_values(array_filter(
                    $hoisted,
                    static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ConstFetch
                ));
                $arrayProducers = array_values(array_filter(
                    $hoisted,
                    static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
                ));
                $target = match ((int) $argIndex) {
                    0 => $constFetches[0] ?? null,
                    2 => $constFetches[1] ?? null,
                    3 => $arrayProducers[\count($arrayProducers) - 1] ?? null,
                    default => null,
                };
                if ($target instanceof Op\Expr\ConstFetch) {
                    $folded = $this->tryFoldGlobalConstFetch($target);
                    if (null !== $folded) {
                        $valueSlot = (string) $block->registerConstant(new Operand\Temporary(), $folded);
                    } else {
                        $slot = $block->slotForOperand($target->result);
                        if (null === $slot) {
                            foreach ($this->compileExpr($target, $block) as $op) {
                                $sends[] = $op;
                            }
                            $slot = $block->slotForOperand($target->result);
                        }
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                    }
                } elseif ($target instanceof Op\Expr\Array_) {
                    $slot = $block->slotForOperand($target->result);
                    if (null === $slot) {
                        foreach ($this->compileArrayLiteral($target, $block) as $op) {
                            $sends[] = $op;
                        }
                        $slot = $block->slotForOperand($target->result);
                    }
                    if (null !== $slot) {
                        $valueSlot = (string) $slot;
                    }
                }
            }
            if (
                'array_map' === strtolower($calleeName ?? '')
                && 0 === (int) $argIndex
                && null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                    $fccSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                    if (null !== $fccSlot) {
                        $valueSlot = (string) $fccSlot;
                    }
                } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                    || $leadingCallback instanceof Op\Expr\Closure) {
                    $closureSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                    if (null !== $closureSlot) {
                        $valueSlot = (string) $closureSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($calleeName ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $mergeCallArg = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
                if (
                    $mergeCallArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($mergeCallArg)
                    && $this->callArgOperandExpectsArrayProducer($mergeCallArg)
                ) {
                    $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                        $block->orig->children,
                        $cfgCallOp
                    );
                    $mergeArgCount = \count($cfgCallOp->args ?? []);
                    if ($mergeArgCount >= 2 && \count($mergeProducers) >= 2) {
                        if (
                            0 === (int) $argIndex
                            && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
                        ) {
                            $leadingInitSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                            if (null !== $leadingInitSlot) {
                                $valueSlot = $leadingInitSlot;
                                $inlineArrayLiteralArgWired = true;
                            }
                        }
                        if (null === $valueSlot) {
                        $mergeMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                            $mergeProducers,
                            (int) $argIndex,
                            $mergeArgCount,
                            is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                        );
                        if ($mergeMapped instanceof Op\Expr) {
                            $mergeSlot = $block->slotForOperand($mergeMapped->result);
                            if (
                                null === $mergeSlot
                                && $mergeMapped instanceof Op\Expr\Array_
                                && 0 === (int) $argIndex
                            ) {
                                $mergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                            }
                            if (null === $mergeSlot) {
                                foreach ($this->compileExpr($mergeMapped, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $mergeSlot = $block->slotForOperand($mergeMapped->result);
                                if (
                                    null === $mergeSlot
                                    && $mergeMapped instanceof Op\Expr\Array_
                                    && 0 === (int) $argIndex
                                ) {
                                    $mergeSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                                }
                            }
                            if (null !== $mergeSlot) {
                                $valueSlot = (string) $mergeSlot;
                                if ($mergeMapped instanceof Op\Expr\Array_) {
                                    $inlineArrayLiteralArgWired = true;
                                }
                            }
                        }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && !$this->callArgOperandExpectsArrayProducer($arg)
                && !$inlineArrayLiteralArgWired
            ) {
                $andPhi = $this->logicalShortCircuitPhiMergeSlot($block);
                if (
                    null !== $andPhi
                    && null !== $valueSlot
                    && $this->isNamedVariableOperand($arg)
                ) {
                    $namedSlot = $block->slotForNamedAssignDest($arg) ?? (int) $valueSlot;
                    $copyOperand = new Operand\Temporary();
                    $copySlot = $block->forceFreshVarSlot($copyOperand, (int) $andPhi);
                    $sends[] = new OpCode(
                        OpCode::TYPE_ASSIGN,
                        $copySlot,
                        $copySlot,
                        $namedSlot,
                    );
                    $valueSlot = (string) $copySlot;
                } elseif (
                    null !== $andPhi
                    && null !== $valueSlot
                    && (string) $valueSlot === (string) $andPhi
                    && $this->callArgIsDeadInlineTemporary($arg)
                ) {
                    $pendingNested = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($sends)
                        ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
                    $calleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                    if (
                        null !== $pendingNested
                        && !\in_array($calleeLower, ['exit', 'die'], true)
                        && !(
                            'array_map' === $calleeLower
                            && 0 === (int) $argIndex
                        )
                    ) {
                        $valueSlot = (string) $pendingNested;
                    }
                }
            }
            if (
                'array_multisort' === strtolower($calleeName ?? '')
                && null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $multisortArgProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
                if (
                    $this->callArgIsDeadInlineTemporary($multisortArgProbe)
                    && !$this->isCallArgDirectArrayDimFetch($multisortArgProbe)
                    && null === $this->resolvePrecedingArrayDimFetchCallArgSlot(
                        $multisortArgProbe,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    )
                ) {
                    // Inline literal — assign-in-call may sit between the second Array_ and FuncCall (#15151).
                    $stmtBefore = $this->inlineArrayMultisortLiteralProducerForArg(
                        $cfgCallOp,
                        $block,
                        (int) $argIndex
                    ) ?? $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                    if ($stmtBefore instanceof Op\Expr\Array_) {
                        if (null === $block->slotForOperand($stmtBefore->result)) {
                            foreach ($this->compileArrayLiteral($stmtBefore, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $leadArraySlot = $block->slotForOperand($stmtBefore->result);
                        if (null !== $leadArraySlot) {
                            $valueSlot = (string) $leadArraySlot;
                        }
                    }
                }
            }
            if (null !== $cfgCallOp) {
                $assignInCallArg = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if (
                    $this->callArgIsAssignInCallOperand($assignInCallArg)
                    || (
                        null !== $calleeName
                        && $this->callArgRequiresByRef($calleeName, (int) $argIndex, $arg, $block)
                    )
                ) {
                    $assignInCallRhs = $this->resolveAssignInCallRhsCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $arg
                    );
                    if (null !== $assignInCallRhs) {
                        $valueSlot = $assignInCallRhs;
                    }
                }
            }
            $procOpenSlot = $this->resolveProcOpenInlineCallArgSlot($block, $cfgCallOp, (int) $argIndex, $sends);
            if (null !== $procOpenSlot) {
                $valueSlot = $procOpenSlot;
            }
            $ternaryMergeSlot = $this->resolveNestedTernaryMergeCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $callArgOperand ?? $arg
            );
            if (
                null !== $ternaryMergeSlot
                && !(
                    $this->callArgIsDeadInlineTemporary($arg)
                    && $this->callArgOperandExpectsArrayProducer($arg)
                )
            ) {
                $valueSlot = $ternaryMergeSlot;
            }
            if (
                null !== $cfgCallOp
                && $this->callArgUsesHaystackFamilyArrayProducerResolution($cfgCallOp, (int) $argIndex, $calleeName, $arg)
                && !$inlineArrayLiteralArgWired
            ) {
                $arrayProducerSlot = null;
                $siblingEmit = [];
                $siblingFuncCount = 0;
                if (null !== $block->orig) {
                    $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (null !== $callIndex) {
                        $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex(
                            $callIndex,
                            $block->orig->children
                        );
                        if (null !== $firstSibling) {
                            $siblingFuncCount = $this->countSiblingInlineFuncCallProducers(
                                $firstSibling,
                                $callIndex,
                                $block->orig->children
                            );
                        }
                    }
                }
                if ($siblingFuncCount >= 2) {
                    $useOuterMultiArraySetOpWiring = \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_intersect', 'array_diff', 'array_intersect_key', 'array_diff_key'],
                        true
                    );
                    if ($useOuterMultiArraySetOpWiring) {
                        // array_intersect(f(g()), f(g())) — outer EXEC_RETURN per arg (#15488, #16280).
                        $arrayProducerSlot = $this->outerSiblingInlineCallArgProducerExecReturnSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex,
                            $siblingEmit
                        );
                    }
                    if (null === $arrayProducerSlot) {
                        // array_intersect_assoc(array_keys(), array_keys()) — ordinal sibling wiring (#13778, #15570).
                        $arrayProducerSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex,
                            $siblingEmit
                        );
                    }
                    if (null === $arrayProducerSlot) {
                        $arrayProducerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                    }
                } else {
                    // in_array('x', g(), true) — lone nested producer after stmt calls (#15612, #15829).
                    $arrayProducerSlot = $this->findInlineExprCallArgProducerSlot($arg, $block, $cfgCallOp);
                    if (null === $arrayProducerSlot) {
                        $arrayProducerSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex,
                            $siblingEmit
                        );
                    }
                }
                if ([] !== $siblingEmit) {
                    $sends = array_merge($sends, $siblingEmit);
                }
                if (null !== $arrayProducerSlot) {
                    $substrReplaceMapped = null !== $inlineArray
                        && 'substr_replace' === $this->resolveCfgFuncCallName($cfgCallOp);
                    if (!$substrReplaceMapped) {
                        $valueSlot = (string) $arrayProducerSlot;
                        $outerMultiArraySetOpArgWired = true;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \is_array($cfgCallOp->args ?? null)
                && 2 === \count($cfgCallOp->args)
                && 'array_slice' !== $this->resolveCfgFuncCallName($cfgCallOp)
                && 'array_combine' !== $this->resolveCfgFuncCallName($cfgCallOp)
                && !(
                    isset($cfgCallOp->args[0])
                    && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[0])
                )
                && !$this->consumerImmediateUnaryHoistedDeadTempArgZero($cfgCallOp, $block)
            ) {
                $constFuncPrelude = $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block)
                    ?? $this->splitLeadingConstFetchWithFuncCallCallArg(
                        $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                    );
                if (null !== $constFuncPrelude) {
                    [$constFetch, $funcProducer] = $constFuncPrelude;
                    $target = match ((int) $argIndex) {
                        0 => $constFetch,
                        1 => $funcProducer,
                        default => null,
                    };
                    if ($target instanceof Op\Expr) {
                        if (null === $block->slotForOperand($target->result)) {
                            foreach ($this->compileExpr($target, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $splitSlot = $block->slotForOperand($target->result);
                        if (null !== $splitSlot) {
                            $forcePathExplodePrelude = 'explode' === $this->resolveCfgFuncCallName($cfgCallOp)
                                && $constFetch instanceof Op\Expr\ConstFetch
                                && 'PATH_SEPARATOR' === strtoupper($this->staticNameFromOperand($constFetch->name) ?? '')
                                && (
                                    $funcProducer instanceof Op\Expr\FuncCall
                                    || $funcProducer instanceof Op\Expr\NsFuncCall
                                )
                                && 'get_include_path' === strtolower($this->resolveCfgFuncCallName($funcProducer) ?? '');
                            if (null === $valueSlot || $forcePathExplodePrelude) {
                                $valueSlot = (string) $splitSlot;
                            }
                        }
                    }
                }
            }
            $arraySliceEmit = [];
            $arraySliceSlot = $this->resolveArraySliceInlineCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $arg,
                $arraySliceEmit
            );
            if ([] !== $arraySliceEmit) {
                $sends = array_merge($sends, $arraySliceEmit);
            }
            if (null !== $arraySliceSlot) {
                $valueSlot = $arraySliceSlot;
            } elseif (null !== $cfgCallOp && null !== $block->orig && !$outerMultiArraySetOpArgWired) {
                // Dead hoisted call-arg temps must wire to preceding inline producers, not echo/ternary phi slots (#14419).
                $finalArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($this->callArgIsDeadInlineTemporary($finalArgProbe)) {
                    $finalChainOps = [];
                    $chainedArithmeticSlot = $this->tryResolveChainedArithmeticCallArgSlot(
                        $finalArgProbe,
                        $block,
                        $finalChainOps,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $chainedArithmeticSlot) {
                        if ([] !== $finalChainOps) {
                            $sends = array_merge($sends, $finalChainOps);
                        }
                        $valueSlot = (string) $chainedArithmeticSlot;
                    } else {
                        $chainedConcatSlot = $this->tryResolveChainedConcatCallArgSlot(
                            $finalArgProbe,
                            $block,
                            $finalChainOps,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $chainedConcatSlot) {
                            if ([] !== $finalChainOps) {
                                $sends = array_merge($sends, $finalChainOps);
                            }
                            $valueSlot = (string) $chainedConcatSlot;
                        } else {
                            $finalProducers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $mergeFinalMapped = null;
                            $mergeCalleeLower = strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '');
                            if (
                                \in_array(
                                    $mergeCalleeLower,
                                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                                    true
                                )
                            ) {
                                $mergeFinalProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                                    $block->orig->children,
                                    $cfgCallOp
                                );
                                $mergeFinalMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                                    $mergeFinalProducers,
                                    (int) $argIndex,
                                    \count($cfgCallOp->args ?? []),
                                    is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                                );
                            }
                            if ($mergeFinalMapped instanceof Op\Expr && null !== $mergeFinalMapped->result) {
                                if (null === $block->slotForOperand($mergeFinalMapped->result)) {
                                    foreach ($this->compileExpr($mergeFinalMapped, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $mergeFinalSlot = $block->slotForOperand($mergeFinalMapped->result);
                                if (null !== $mergeFinalSlot) {
                                    $valueSlot = (string) $mergeFinalSlot;
                                }
                            } else {
                            $dimFetchFinalSlot = $this->resolvePrecedingArrayDimFetchCallArgSlot(
                                $finalArgProbe,
                                $block,
                                $cfgCallOp,
                                (int) $argIndex
                            );
                            if (null !== $dimFetchFinalSlot) {
                                $valueSlot = $dimFetchFinalSlot;
                            } else {
                            $trailingConst = $this->matchNestedArrayTrailingConstFetchCallArgProducer(
                                $finalProducers,
                                $cfgCallOp->args ?? [],
                                (int) $argIndex
                            );
                            // ?: Phi-written / scalar ConstFetch-written args keep specialized wiring —
                            // do not rebind via ordinal preceding producers (#22732, #14419).
                            $keepSpecializedCallArgSlot = $this->callArgTemporaryIsPhiWritten($finalArgProbe)
                                || $this->callArgTemporaryIsScalarConstFetchWritten($finalArgProbe);
                            if (
                                $trailingConst instanceof Op\Expr
                                && !$keepSpecializedCallArgSlot
                            ) {
                                if (null === $block->slotForOperand($trailingConst->result)) {
                                    foreach ($this->compileExpr($trailingConst, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $trailingConstSlot = $block->slotForOperand($trailingConst->result);
                                if (null !== $trailingConstSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                                    $valueSlot = (string) $trailingConstSlot;
                                }
                            } else {
                                $finalProducer = $this->inlineHoistedProducerForCallArgIndex(
                                    $cfgCallOp,
                                    (int) $argIndex,
                                    $finalProducers,
                                    $block->orig->children,
                                    $block
                                );
                                if (
                                    $finalProducer instanceof Op\Expr
                                    && null !== $finalProducer->result
                                    && !$keepSpecializedCallArgSlot
                                ) {
                                    if (null === $block->slotForOperand($finalProducer->result)) {
                                        foreach ($this->compileExpr($finalProducer, $block) as $op) {
                                            $sends[] = $op;
                                        }
                                    }
                                    $finalProducerSlot = $block->slotForOperand($finalProducer->result);
                                    if (null !== $finalProducerSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                                        $valueSlot = (string) $finalProducerSlot;
                                    }
                                }
                            }
                            }
                            }
                        }
                    }
                }
            }
            if (null !== $cfgCallOp) {
                $iifeEmitOps = [];
                $iifeSlot = $this->resolveIifeHoistedFuncCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $iifeEmitOps
                );
                if (null !== $iifeSlot) {
                    if ([] !== $iifeEmitOps) {
                        $sends = array_merge($sends, $iifeEmitOps);
                    }
                    $valueSlot = $iifeSlot;
                }
            }
            if ('array_column' === strtolower($calleeName ?? '') && null !== $cfgCallOp && null !== $block->orig) {
                $arrayColumnSlot = $this->finalizeArrayColumnCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $arrayColumnSlot) {
                    $valueSlot = $arrayColumnSlot;
                }
            }
            if (
                'array_combine' === strtolower($calleeName ?? $this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $arrayCombineSlot = $this->finalizeArrayCombineCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $arrayCombineSlot) {
                    $valueSlot = $arrayCombineSlot;
                }
            }
            $valueSlot = $this->finalizeStmtCoalesceCallArgSlot(
                $arg,
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $valueSlot
            );
            if ('proc_open' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '') && null !== $cfgCallOp) {
                $procOpenSlot = $this->resolveProcOpenInlineCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $procOpenSlot) {
                    $valueSlot = $procOpenSlot;
                }
            }
            if (null !== $cfgCallOp) {
                $streamContextCall = $this->resolveCfgFuncCallName($cfgCallOp);
                $streamContextOptionsArgIndex = match ($streamContextCall) {
                    'stream_context_set_options' => 1,
                    'stream_context_create', 'stream_context_set_default', 'stream_context_get_default' => 0,
                    default => null,
                };
                if (
                    null !== $streamContextOptionsArgIndex
                    && (int) $argIndex === $streamContextOptionsArgIndex
                ) {
                    $contextOptionsArg = $cfgCallOp->args[$streamContextOptionsArgIndex] ?? $arg;
                    if (
                        $this->callArgIsDeadInlineTemporary($contextOptionsArg)
                        && $this->callArgOperandExpectsArrayProducer($contextOptionsArg)
                    ) {
                        $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                        if (null !== $outerSlot) {
                            $valueSlot = $outerSlot;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'filter_var' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && 2 === (int) $argIndex
            ) {
                $optionsArg = $cfgCallOp->args[2] ?? $arg;
                if ($this->callArgIsDeadInlineTemporary($optionsArg)) {
                    // Typed array[] options (#12007). Unknown-typed nested options with
                    // FILTER_FLAG_* ConstFetch elements still need the outermost INIT_ARRAY (#22772).
                    $useOutermostOptionsArray = $this->callArgOperandExpectsArrayProducer($optionsArg);
                    if (!$useOutermostOptionsArray && null !== $block->orig) {
                        $useOutermostOptionsArray = null !== $this->splitLeadingConstFetchWithNestedArrayLiteralChain(
                            $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                        );
                    }
                    if ($useOutermostOptionsArray) {
                        $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                        if (null !== $outerSlot) {
                            $valueSlot = $outerSlot;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && 3 === (int) $argIndex
            ) {
                $optionsArg = $cfgCallOp->args[3] ?? $arg;
                if ($this->callArgIsDeadInlineTemporary($optionsArg)) {
                    $useOutermostOptionsArray = $this->callArgOperandExpectsArrayProducer($optionsArg);
                    if (!$useOutermostOptionsArray && null !== $block->orig) {
                        $useOutermostOptionsArray = null !== $this->splitLeadingConstFetchWithNestedArrayLiteralChain(
                            $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp)
                        );
                    }
                    if ($useOutermostOptionsArray) {
                        $outerSlot = $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $sends);
                        if (null !== $outerSlot) {
                            $valueSlot = $outerSlot;
                        }
                    }
                }
            }
            $filterInputSlot = $this->finalizeFilterInputCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex,
                $sends
            );
            if (null !== $filterInputSlot) {
                $valueSlot = $filterInputSlot;
            }
            // var_dump(E::A <=> E::B) — immediate spaceship prelude wins over hoisted enum temps (#10203).
            if (null !== $cfgCallOp && null !== $block->orig && 0 === (int) $argIndex) {
                $callArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($this->callArgIsDeadInlineTemporary($callArgProbe)) {
                    $cfgCallIndex = null;
                    foreach ($block->orig->children as $ci => $cfgChild) {
                        if ($cfgChild === $cfgCallOp) {
                            $cfgCallIndex = $ci;
                            break;
                        }
                    }
                    if (null !== $cfgCallIndex && $cfgCallIndex > 0) {
                        $immediatePrelude = $block->orig->children[$cfgCallIndex - 1] ?? null;
                        $comparisonPrelude = null;
                        if ($this->isComparisonInlineCallArgProducer($immediatePrelude)) {
                            $comparisonPrelude = $immediatePrelude;
                        } elseif (
                            $cfgCallIndex > 1
                            && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($immediatePrelude)
                        ) {
                            $candidate = $block->orig->children[$cfgCallIndex - 2] ?? null;
                            if ($this->isComparisonInlineCallArgProducer($candidate)) {
                                $comparisonPrelude = $candidate;
                            }
                        }
                        if (
                            $comparisonPrelude instanceof Op\Expr
                            && null !== $comparisonPrelude->result
                        ) {
                            if (null === $block->slotForOperand($comparisonPrelude->result)) {
                                foreach ($this->compileExpr($comparisonPrelude, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $comparisonSlot = $block->slotForOperand($comparisonPrelude->result);
                            if (null !== $comparisonSlot) {
                                $valueSlot = (string) $comparisonSlot;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && null === $this->findStmtCoalesceImmediatelyBeforeFuncCall($cfgCallOp, $block)
            ) {
                $chainedDimFetch = $this->matchChainedArrayDimFetchInlineCallArgProducer(
                    $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp),
                    (int) $argIndex
                );
                if ($chainedDimFetch instanceof Op\Expr && null !== $chainedDimFetch->result) {
                    if (null === $block->slotForOperand($chainedDimFetch->result)) {
                        foreach ($this->compileExpr($chainedDimFetch, $block) as $op) {
                            $sends[] = $op;
                        }
                    }
                    $chainSlot = $block->slotForOperand($chainedDimFetch->result);
                    if (null === $chainSlot) {
                        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                            $block->orig->children,
                            $cfgCallOp
                        );
                        $dimFetches = array_values(array_filter(
                            $producers,
                            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\ArrayDimFetch
                        ));
                        if (
                            \count($dimFetches) >= 2
                            && $this->arrayDimFetchesFormProducerChain($dimFetches)
                        ) {
                            $chainSlot = $this->pendingCallArgArrayDimFetchSlot(
                                $block,
                                $sends,
                                \count($dimFetches) - 1
                            );
                        }
                    }
                    if (null !== $chainSlot) {
                        $valueSlot = (string) $chainSlot;
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $dateSunSlot = $this->wireDateSunFuncHoistedCallArgSlot($block, $cfgCallOp, (int) $argIndex);
                if (null !== $dateSunSlot) {
                    $valueSlot = $dateSunSlot;
                }
                if (null === $valueSlot) {
                    $arraySpliceSlot = $this->wireArraySpliceUnaryOffsetReplacementCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $sends
                    );
                    if (null !== $arraySpliceSlot) {
                        $valueSlot = $arraySpliceSlot;
                    }
                }
                if (null === $valueSlot) {
                    $mbstringSlot = $this->wireMbstringUnaryOffsetNullLengthCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $sends
                    );
                    if (null !== $mbstringSlot) {
                        $valueSlot = $mbstringSlot;
                    }
                }
            }
            $finalCallArgProbe = $arg;
            if (null !== $cfgCallOp && is_array($cfgCallOp->args ?? null) && isset($cfgCallOp->args[(int) $argIndex])) {
                $finalCallArgProbe = $cfgCallOp->args[(int) $argIndex];
            }
            $finalNamedSlot = $this->namedLocalCallArgSlotIfBound(
                $finalCallArgProbe,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) ?? $this->slotForNamedLocalFromAssignVarOperand($finalCallArgProbe, $block);
            if (null === $finalNamedSlot) {
                $finalVarName = Block::resolveVariableName($finalCallArgProbe);
                if (null !== $finalVarName && '' !== $finalVarName) {
                    $finalNamedSlot = $block->slotIndexForVariableName($finalVarName);
                }
            }
            if (null !== $finalNamedSlot) {
                $skipFinalNamedForSiblingExec = $this->callArgIsDeadInlineTemporary($finalCallArgProbe)
                    && null !== $cfgCallOp
                    && \count($cfgCallOp->args ?? []) >= 2
                    && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp);
                if (!$skipFinalNamedForSiblingExec) {
                    $finalNamedAssignDest = $block->slotForNamedAssignDest($finalCallArgProbe);
                    $valueSlot = null !== $finalNamedAssignDest
                        ? $this->resolveNamedAssignCallArgSlot(
                            $block,
                            (int) $finalNamedAssignDest,
                            $calleeName,
                            (int) $argIndex,
                            $finalCallArgProbe
                        )
                        : (string) $this->finalizeOperandSlotForAccess($block, (int) $finalNamedSlot, true);
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'filter_input' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && (0 === (int) $argIndex || 2 === (int) $argIndex)
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (null !== $callIndex) {
                    $wantPrefix = 0 === (int) $argIndex ? 'input_' : 'filter_';
                    for ($i = $callIndex - 1; $i >= 0; --$i) {
                        $child = $block->orig->children[$i];
                        if (!$child instanceof Op\Expr\ConstFetch) {
                            if ($child instanceof Op\Expr\Assign) {
                                break;
                            }
                            continue;
                        }
                        $name = strtolower($this->staticNameFromOperand($child->name) ?? '');
                        if (!str_starts_with($name, $wantPrefix)) {
                            continue;
                        }
                        $slot = $block->slotForOperand($child->result);
                        if (null !== $slot) {
                            $valueSlot = (string) $slot;
                        }
                        break;
                    }
                }
            }
            // filter_var($v, FILTER_*, FLAGS|FLAGS) — hoisted filter const is not the trailing BitwiseOr (#17410).
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'filter_var' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && \is_array($cfgCallOp->args ?? null)
                && 3 === \count($cfgCallOp->args)
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (null !== $callIndex) {
                    if (1 === (int) $argIndex) {
                        for ($i = $callIndex - 1; $i >= 0; --$i) {
                            $child = $block->orig->children[$i];
                            if (!$child instanceof Op\Expr\ConstFetch) {
                                if ($child instanceof Op\Expr\Assign) {
                                    break;
                                }
                                continue;
                            }
                            $name = strtolower($this->staticNameFromOperand($child->name) ?? '');
                            if (
                                !str_starts_with($name, 'filter_')
                                || $this->isFilterVarOptionFlagConstName($name)
                            ) {
                                continue;
                            }
                            if (null === $block->slotForOperand($child->result)) {
                                foreach ($this->compileExpr($child, $block) as $op) {
                                    $sends[] = $op;
                                }
                            }
                            $filterSlot = $block->slotForOperand($child->result);
                            if (null !== $filterSlot) {
                                $valueSlot = (string) $filterSlot;
                            }
                            break;
                        }
                    } elseif (2 === (int) $argIndex) {
                        $optionsArg = $cfgCallOp->args[2] ?? $arg;
                        if (
                            $this->callArgIsDeadInlineTemporary($optionsArg)
                            && !$this->callArgOperandExpectsArrayProducer($optionsArg)
                        ) {
                            $immediate = $block->orig->children[$callIndex - 1] ?? null;
                            if (
                                $immediate instanceof Op\Expr\BinaryOp\BitwiseOr
                                || $immediate instanceof Op\Expr\BinaryOp\BitwiseAnd
                                || $immediate instanceof Op\Expr\BinaryOp\BitwiseXor
                                || $immediate instanceof Op\Expr\ConstFetch
                            ) {
                                if (null === $block->slotForOperand($immediate->result)) {
                                    foreach ($this->compileExpr($immediate, $block) as $op) {
                                        $sends[] = $op;
                                    }
                                }
                                $optionsSlot = $block->slotForOperand($immediate->result);
                                if (null !== $optionsSlot) {
                                    $valueSlot = (string) $optionsSlot;
                                }
                            }
                        }
                    }
                }
            }
            // probe('label', in_array(..., g(), true)) — nested callee return, not inner ConstFetch (#14237, #16013).
            // array_slice([..], array_search(...)) — keep resolveArraySliceInlineCallArgSlot haystack/offset (#13684, #16023).
            $nestedCallArgSlot = null;
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && null === $arraySliceSlot
                && !$inlineArrayLiteralArgWired
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                && !$this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)
                && !$this->immediatePredecessorIsInlineBitmaskProducer($cfgCallOp, $block)
            ) {
                $nestedCallArgSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                // Do not clobber a ClassConstFetch/flag already wired for this arg
                // (new CachingIterator(new ArrayIterator(...), CachingIterator::FULL_CACHE) — #19769).
                if (null !== $nestedCallArgSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                    $valueSlot = $nestedCallArgSlot;
                }
            }
            if (
                null !== $nestedCallArgSlot
                || null === $cfgCallOp
                || null === $block->orig
                || !$this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
            ) {
                // keep resolved slot
            } elseif ($this->shouldSkipFinalAdjacentNestedFuncCallArgProbe($cfgCallOp, (int) $argIndex, $block)) {
                // array_merge(['a'=>1], array_keys(...)) — arg #0 is leading Array_, not adjacent FuncCall (#16028).
            } elseif (
                null === $valueSlot
                && !(
                    null !== $cfgCallOp
                    && null !== $block->orig
                    && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                    && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                )
            ) {
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($callIndex) && $callIndex > 0) {
                    $prevStmt = $block->orig->children[$callIndex - 1] ?? null;
                    if ($prevStmt instanceof Op\Expr\FuncCall || $prevStmt instanceof Op\Expr\NsFuncCall) {
                        $adjacentExec = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $callIndex - 1,
                            $block->orig->children
                        );
                        if (null !== $adjacentExec) {
                            $nestedCallArgSlot = (string) $adjacentExec;
                            $valueSlot = $nestedCallArgSlot;
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null === $nestedCallArgSlot
                && !($cfgCallOp instanceof Op\Expr\MethodCall)
                && !($cfgCallOp instanceof Op\Expr\NullsafeMethodCall)
            ) {
                $immediatePropertyOrMethodSlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                    $block,
                    $cfgCallOp,
                    false
                );
                if (null !== $immediatePropertyOrMethodSlot) {
                    // Property/method prelude — do not clobber ClassConstFetch/flag wiring (#19769).
                    if (!$hoistedEnumPropertyCallArgSlotWired) {
                        $valueSlot = $immediatePropertyOrMethodSlot;
                    }
                } elseif (null !== $block->orig) {
                    if (null === $valueSlot) {
                        $siblingSendSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                        if (null !== $siblingSendSlot) {
                            $valueSlot = $siblingSendSlot;
                        }
                    }
                }
            } elseif (null !== $cfgCallOp && null !== $block->orig) {
                $siblingSendSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                if (null !== $siblingSendSlot && !$hoistedEnumPropertyCallArgSlotWired) {
                    $valueSlot = $siblingSendSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null)
                && null !== $block->orig
            ) {
                $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero(
                    $block,
                    $cfgCallOp,
                    $sends
                );
                if (null !== $hoistedScalarArgSlot) {
                    $valueSlot = $hoistedScalarArgSlot;
                } else {
                    $varExportProducerSlot = $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        0
                    );
                    if (null !== $varExportProducerSlot) {
                        $valueSlot = $varExportProducerSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && 'var_export' === $this->resolveCfgFuncCallName($cfgCallOp)
            ) {
                $varExportArg = $cfgCallOp->args[0] ?? $arg;
                if (
                    $varExportArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($varExportArg)
                    && $this->callArgOperandExpectsArrayProducer($varExportArg)
                    && null !== $block->orig
                ) {
                    $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    $stmtBeforeVarExport = \is_int($callIndex)
                        ? ($block->orig->children[$callIndex - 1] ?? null)
                        : null;
                    if (!$stmtBeforeVarExport instanceof Op\Expr\BinaryOp\Plus) {
                        $stmtBeforeArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block);
                        if ($stmtBeforeArray instanceof Op\Expr\Array_) {
                            $arrayArgSlot = $this->slotForRecentInitArrayCallArg($block);
                            if (null === $arrayArgSlot) {
                                $arrayArgSlot = $block->slotForOperand($stmtBeforeArray->result);
                            }
                            if (null === $arrayArgSlot) {
                                foreach ($this->compileArrayLiteral($stmtBeforeArray, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $arrayArgSlot = $this->slotForRecentInitArrayCallArg($block)
                                    ?? $block->slotForOperand($stmtBeforeArray->result);
                            }
                            if (null !== $arrayArgSlot) {
                                $valueSlot = (string) $arrayArgSlot;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && 'array_combine' === $this->resolveCfgFuncCallName($cfgCallOp)
                && null !== $block->orig
            ) {
                $combineForcedSlot = $this->finalizeArrayCombineCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $combineForcedSlot) {
                    $valueSlot = $combineForcedSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $mergeForcedSlot = $this->finalizeArrayMergeFamilyCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $mergeForcedSlot) {
                    $valueSlot = $mergeForcedSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && (int) $argIndex > 0
                && \is_array($cfgCallOp->args ?? null)
                && (int) $argIndex === \count($cfgCallOp->args) - 1
                && $this->callHasNamedVariableArgument($cfgCallOp)
            ) {
                $mixedCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                if (\is_int($mixedCallIndex) && $mixedCallIndex > 0) {
                    $adjacentProducer = $block->orig->children[$mixedCallIndex - 1] ?? null;
                    if ($adjacentProducer instanceof Op\Expr\FuncCall || $adjacentProducer instanceof Op\Expr\NsFuncCall) {
                        $adjacentExec = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                            $block,
                            $mixedCallIndex - 1,
                            $block->orig->children
                        );
                        if (null !== $adjacentExec) {
                            $valueSlot = (string) $adjacentExec;
                        }
                    }
                }
            }
            if ('proc_open' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '') && null !== $cfgCallOp) {
                $procOpenFinalSlot = $this->resolveProcOpenInlineCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $procOpenFinalSlot) {
                    $valueSlot = $procOpenFinalSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[(int) $argIndex] ?? $arg)
                && !$this->arrayCombineSkipsSiblingFuncExecArgSlot($cfgCallOp, (int) $argIndex, $block)
            ) {
                $constPreludeSlot = $this->slotForImmediateConstFetchPreludeCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $constPreludeSlot) {
                    $valueSlot = $constPreludeSlot;
                }
                $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                $firstSibling = \is_int($callIndex)
                    ? $this->firstSiblingInlineFuncCallProducerIndexImpl($callIndex, $block->orig->children)
                    : null;
                if (\is_int($callIndex) && \is_int($firstSibling)) {
                    if (
                        0 === (int) $argIndex
                        && 'array_map' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                    ) {
                        $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                        if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                            $fccSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                            if (null !== $fccSlot) {
                                $valueSlot = (string) $fccSlot;
                            }
                        } elseif ($leadingCallback instanceof Op\Expr\ArrowFunction
                            || $leadingCallback instanceof Op\Expr\Closure) {
                            $closureSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                            if (null !== $closureSlot) {
                                $valueSlot = (string) $closureSlot;
                            }
                        }
                        if (null === $valueSlot) {
                            for ($scan = \count($block->opCodes) - 1; $scan >= 0; --$scan) {
                                $scanOp = $block->opCodes[$scan];
                                if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                                    $valueSlot = (string) $scanOp->arg1;
                                    break;
                                }
                            }
                        }
                    }
                    if (
                        0 === (int) $argIndex
                        && \in_array(
                            strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                            true
                        )
                        && ($mergeLeadingArray = $this->inlineArrayProducerImmediatelyBeforeCfgCall($cfgCallOp, $block)) instanceof Op\Expr\Array_
                    ) {
                        // array_merge(['a'=>1], $o) — arg #0 is stmt-before Array_, not distant sibling EXEC (#16299).
                        if (null === $block->slotForOperand($mergeLeadingArray->result)) {
                            foreach ($this->compileArrayLiteral($mergeLeadingArray, $block) as $op) {
                                $sends[] = $op;
                            }
                        }
                        $mergeLeadSlot = $block->slotForOperand($mergeLeadingArray->result)
                            ?? $this->slotForRecentInitArrayCallArg($block);
                        if (null !== $mergeLeadSlot) {
                            $valueSlot = (string) $mergeLeadSlot;
                        }
                    }
                    $chainProducerCount = $this->countSiblingInlineFuncCallProducers(
                        $firstSibling,
                        $callIndex,
                        $block->orig->children
                    );
                    $producerOrdinal = $this->inlineHoistedProducerSlotIndexForCallArg(
                        $cfgCallOp->args ?? $args,
                        (int) $argIndex,
                        $block,
                        $cfgCallOp
                    );
                    $consumerName = $this->resolveCfgFuncCallName($cfgCallOp) ?? $calleeName;
                    $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($consumerName);
                    $leadingCallback = $this->leadingCallbackFirstInlineProducerBeforeCfgCall($cfgCallOp, $block);
                    if (
                        $callbackArgIndex >= 0
                        && 2 === \count($cfgCallOp->args ?? [])
                        && (int) $argIndex === $callbackArgIndex
                        && ($leadingCallback instanceof Op\Expr\ArrowFunction
                            || $leadingCallback instanceof Op\Expr\Closure
                            || $leadingCallback instanceof Op\Expr\FirstClassCallable)
                    ) {
                        // array_map(intval(...), str_split(str_repeat(...))) — ordinal ExecReturn must not steal FCC callback (#15487, #16279).
                        if ($leadingCallback instanceof Op\Expr\FirstClassCallable) {
                            $callbackSlot = $this->slotForInlineFirstClassCallableProducer($leadingCallback, $block);
                        } else {
                            $callbackSlot = $this->slotForInlineClosureProducer($leadingCallback, $block);
                        }
                        if (null !== $callbackSlot) {
                            $valueSlot = (string) $callbackSlot;
                        }
                    } elseif (
                        null !== $producerOrdinal
                        && $producerOrdinal < $chainProducerCount
                        && null === $valueSlot
                        && !$this->callArgHasHoistedConstPrelude($cfgCallOp, (int) $argIndex, $block)
                    ) {
                        $execReturnCount = $block->funccallExecReturnCount();
                        $execOrdinal = $execReturnCount - $chainProducerCount + $producerOrdinal;
                        $forcedSiblingSlot = $this->slotForSiblingInlineFuncCallProducerExecReturnOrdinal(
                            $block,
                            $execOrdinal
                        );
                        if (null !== $forcedSiblingSlot) {
                            $valueSlot = (string) $forcedSiblingSlot;
                        }
                    }
                }
                $forcedSiblingSlot = $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                if (null === $forcedSiblingSlot) {
                    $forcedSiblingOps = [];
                    $forcedSiblingSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $forcedSiblingOps
                    );
                    if ([] !== $forcedSiblingOps) {
                        $sends = array_merge($sends, $forcedSiblingOps);
                    }
                }
                if (null !== $forcedSiblingSlot) {
                    if (
                        0 === (int) $argIndex
                        && (
                            $inlineArrayLiteralArgWired
                            || $this->arrayMergeFamilyLeadingInlineArrayArgUsesHoistedArray($cfgCallOp, (int) $argIndex, $block)
                        )
                    ) {
                        if (null === $valueSlot) {
                            $mergeProducers = $this->arrayMergeFamilyInlineProducersForCfgCall(
                                $block->orig->children,
                                $cfgCallOp
                            );
                            $leadingMapped = $this->matchArrayMergeFamilyFullInlineCallArgProducer(
                                $mergeProducers,
                                0,
                                \count($cfgCallOp->args ?? []),
                                is_array($cfgCallOp->args ?? null) ? $cfgCallOp->args : []
                            );
                            if ($leadingMapped instanceof Op\Expr\Array_) {
                                $leadingSlot = $block->slotForOperand($leadingMapped->result)
                                    ?? $this->slotForInitArrayOrdinal($block, 0, $sends);
                                if (null !== $leadingSlot) {
                                    $valueSlot = (string) $leadingSlot;
                                }
                            }
                        }
                    } elseif (
                        null === $valueSlot
                        && null === $constPreludeSlot
                        && !$this->callArgHasHoistedConstPrelude($cfgCallOp, (int) $argIndex, $block)
                    ) {
                        $valueSlot = (string) $forcedSiblingSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_intersect', 'array_diff', 'array_intersect_key', 'array_diff_key'],
                    true
                )
                && $this->countDeadArrayInlineCallArgs($cfgCallOp) >= 2
            ) {
                $multiOuterExec = $this->outerSiblingInlineCallArgProducerExecReturnSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $multiOuterExec) {
                    $valueSlot = $multiOuterExec;
                }
            }
            $literalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if ($this->isEmbeddedCallLiteralArg($literalProbe)) {
                // Must run after sibling/adjacent wiring — do not alias prior EXEC_RETURN (#16254, array_slice #10229).
                $valueSlot = (string) $this->freshLiteralConstantSlot($literalProbe, $block);
            }
            $sendProbe = $literalProbe;
            $sendName = Block::resolveVariableName($sendProbe);
            if (null !== $sendName && '' !== $sendName) {
                $paramSlot = $block->paramSlotForName($sendName);
                if (null !== $paramSlot) {
                    $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, $paramSlot, true);
                }
            }
            $postSuppressAssignSlot = $this->slotForPostErrorSuppressAssignNamedLocalCallArg($sendProbe, $block);
            if (null !== $postSuppressAssignSlot) {
                $valueSlot = (string) $postSuppressAssignSlot;
            }
            // substr(sprintf('%o', fileperms($path)), -N) — adjacent nested wiring must not clobber named path locals (#13636, #16055).
            $sendNamedLocalSlot = $this->namedLocalCallArgSlotIfBound(
                $sendProbe,
                $block,
                $cfgCallOp,
                (int) $argIndex
            ) ?? $this->slotForNamedLocalFromAssignVarOperand($sendProbe, $block);
            if (null !== $sendNamedLocalSlot) {
                if (
                    $this->callArgIsDeadInlineTemporary($sendProbe)
                    && null !== $valueSlot
                    && null !== $cfgCallOp
                    && \count($cfgCallOp->args ?? []) >= 2
                    && $this->hasSiblingMultiArgInlineCallProducers($block, $cfgCallOp)
                ) {
                    // Keep sibling/nested EXEC_RETURN wiring — do not remap dead temps to $path locals (#16480).
                } else {
                    $sendNamedAssignDest = $block->slotForNamedAssignDest($sendProbe);
                    $valueSlot = null !== $sendNamedAssignDest
                        ? $this->resolveNamedAssignCallArgSlot(
                            $block,
                            (int) $sendNamedAssignDest,
                            $calleeName,
                            (int) $argIndex,
                            $sendProbe
                        )
                        : (string) $this->finalizeOperandSlotForAccess($block, (int) $sendNamedLocalSlot, true);
                }
            }
            // probe('label', in_array(...)) — lone nested callee EXEC_RETURN, not strict/haystack operand (#16312).
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \is_array($cfgCallOp->args ?? null)
                && $this->callArgIsDeadInlineTemporary($sendProbe)
            ) {
                $deadTempCount = 0;
                foreach ($cfgCallOp->args as $deadArg) {
                    if ($this->callArgIsDeadInlineTemporary($deadArg)) {
                        ++$deadTempCount;
                    }
                }
                if (1 === $deadTempCount) {
                    $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (\is_int($callIndex) && $callIndex > 0) {
                        $adjacentIndex = $callIndex - 1;
                        while ($adjacentIndex >= 0) {
                            $skip = $block->orig->children[$adjacentIndex] ?? null;
                            if ($skip instanceof Op\Expr\ConstFetch || $skip instanceof Op\Expr\ClassConstFetch) {
                                --$adjacentIndex;
                                continue;
                            }
                            break;
                        }
                        $adjacent = $block->orig->children[$adjacentIndex] ?? null;
                        if (
                            ($adjacent instanceof Op\Expr\FuncCall || $adjacent instanceof Op\Expr\NsFuncCall)
                            && $this->isAdjacentNestedFuncCallProducer(
                                $adjacent,
                                $cfgCallOp,
                                $adjacentIndex,
                                $callIndex
                            )
                        ) {
                            $singleNestedExec = $this->slotForLastPendingInlineCallResultBeforeFuncCallInit($sends)
                                ?? $this->slotForLastEmittedInlineCallResultBeforePendingFuncCall($block);
                            if (null !== $singleNestedExec) {
                                $valueSlot = (string) $singleNestedExec;
                            }
                        }
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
                && 'array_pad' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $this->callArgIsDeadInlineTemporary($sendProbe)
            ) {
                $padHaystackSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                if (null !== $padHaystackSlot) {
                    $valueSlot = $padHaystackSlot;
                    $inlineArrayLiteralArgWired = true;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 0 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
                && null === $valueSlot
                && $this->arrayMergeHasLeadingInlineArrayBeforeArrayKeysSibling($block, $cfgCallOp)
            ) {
                $leadingMergeFinalSlot = $this->slotForInitArrayOrdinal($block, 0, $sends);
                if (null !== $leadingMergeFinalSlot) {
                    $valueSlot = $leadingMergeFinalSlot;
                    $inlineArrayLiteralArgWired = true;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && $this->callArgIsDeadInlineTemporary($sendProbe)
                && $this->callArgUsesHaystackFamilyArrayProducerResolution(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $sendProbe
                )
                && !(
                    0 === (int) $argIndex
                    && \in_array(
                        strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                        ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                        true
                    )
                    && null !== $this->matchArrayMergeFuncCallAndArrayInlineProducers(
                        $this->arrayMergeFamilyInlineProducersForCfgCall($block->orig->children, $cfgCallOp),
                        0
                    )
                )
            ) {
                $haystackSiblingEmit = [];
                $haystackExecSlot = $this->resolveSiblingInlineCallArgProducerSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $haystackSiblingEmit
                );
                if (null !== $haystackExecSlot) {
                    if (
                        0 === (int) $argIndex
                        && \in_array(
                            strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                            ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                            true
                        )
                        && $inlineArrayLiteralArgWired
                        && null !== $valueSlot
                    ) {
                        // array_merge(['a'=>1], array_keys(...)) — arg #0 stays on leading INIT_ARRAY (#13760, #16418).
                    } else {
                        $valueSlot = (string) $haystackExecSlot;
                    }
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $finalIssetEmptySlot = $this->resolveHoistedIssetOrEmptyCallArgSlot(
                    $arg,
                    $block,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null !== $finalIssetEmptySlot) {
                    $valueSlot = (string) $finalIssetEmptySlot;
                } else {
                    $inlineLiteralDimSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    );
                    if (null !== $inlineLiteralDimSlot) {
                        $valueSlot = $inlineLiteralDimSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && 'substr' === strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')
            ) {
                $nestedExecSlot = $this->wireSubstrNestedSprintfCallArgSlot($block, $cfgCallOp, (int) $argIndex, $calleeName)
                    ?? $this->resolveAdjacentNestedFuncCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    ) ?? $this->finalSiblingInlineCallArgSendSlot($block, $cfgCallOp, (int) $argIndex);
                if (null !== $nestedExecSlot) {
                    $valueSlot = $nestedExecSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && $this->isEmbeddedCallLiteralArg($cfgCallOp->args[(int) $argIndex] ?? null)
            ) {
                $valueSlot = $this->compileOperand($cfgCallOp->args[(int) $argIndex], $block, true);
            } elseif (null !== $cfgCallOp && null !== $block->orig) {
                $unaryTailSlot = $this->slotForImmediateUnaryHoistedCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName
                );
                if (null !== $unaryTailSlot) {
                    $valueSlot = $unaryTailSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['is_array', 'count', 'array_keys'],
                    true
                )
            ) {
                $arrayBuiltinArg = $cfgCallOp->args[0] ?? $arg;
                if ($arrayBuiltinArg instanceof Operand && $this->callArgIsDeadInlineTemporary($arrayBuiltinArg)) {
                    $namedLocalSlot = $this->namedLocalCallArgSlotIfBound(
                        $arrayBuiltinArg,
                        $block,
                        $cfgCallOp,
                        (int) $argIndex
                    ) ?? $this->slotForNamedLocalFromAssignVarOperand($arrayBuiltinArg, $block);
                    if (null !== $namedLocalSlot) {
                        $valueSlot = (string) $this->finalizeOperandSlotForAccess($block, (int) $namedLocalSlot, true);
                    } else {
                        $nestedFileSlot = null;
                        $callIndex = $this->cfgCallOpIndex($block, $cfgCallOp);
                        if (\is_int($callIndex) && $callIndex > 0 && null !== $block->orig) {
                            $immediate = $block->orig->children[$callIndex - 1] ?? null;
                            if (
                                ($immediate instanceof Op\Expr\FuncCall || $immediate instanceof Op\Expr\NsFuncCall)
                                && $this->isNestedCallArgProducerForConsumer(
                                    $immediate,
                                    $cfgCallOp,
                                    $callIndex - 1,
                                    $callIndex,
                                    $block->orig->children
                                )
                            ) {
                                $nestedFileSlot = $this->slotForInlineFuncCallProducerExecReturnByCfgIndex(
                                    $block,
                                    $callIndex - 1,
                                    $block->orig->children
                                );
                            }
                        }
                        $nestedFileSlot ??= $this->resolveAdjacentNestedFuncCallArgSlot(
                            $block,
                            $cfgCallOp,
                            (int) $argIndex
                        );
                        if (null !== $nestedFileSlot) {
                            $valueSlot = (string) $nestedFileSlot;
                        }
                    }
                }
            }
            if (null !== $cfgCallOp && !$this->isEmbeddedCallLiteralArg($arg)) {
                $pendingDimFetchSlot = null;
                if (null !== $dimFetchSlot) {
                    // stream_set_blocking($pipes[1], false) — dim-fetch slot is arg #0 only (#18186).
                    $pendingDimFetchSlot = $this->lastPendingCallArgArrayDimFetchSlot($block, $sends);
                    if (null === $pendingDimFetchSlot) {
                        $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
                    }
                } elseif ($this->callArgIsDeadInlineHaystackFamilySlot(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $arg
                )) {
                    $pendingDimFetchSlot = $this->pendingCallArgArrayDimFetchSlot($block, $sends, 0);
                }
                if (null !== $pendingDimFetchSlot) {
                    $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                        $block,
                        $cfgCallOp,
                        false
                    );
                    // The pending scan returns the LAST dim-fetch read, which belongs to the trailing
                    // argument. Applying it to every index made t2($r['a'], $r['b']) send $r['b'] twice
                    // (#23354). Earlier arguments keep the per-index slot resolved above; the override
                    // still runs when nothing else produced one, so it stays a fallback.
                    if (
                        null === $immediatePropertySlot
                        && (
                            null === $valueSlot
                            || (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
                        )
                    ) {
                        $valueSlot = (string) $pendingDimFetchSlot;
                    }
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
            ) {
                $comparisonSlot = $this->slotForComparisonPreludeDeadInlineCallArg(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $comparisonSlot) {
                    $valueSlot = $comparisonSlot;
                }
            }
            if (
                null !== $cfgCallOp
                && null !== $block->orig
                && \in_array(
                    strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                    ['array_merge', 'array_merge_recursive', 'array_replace', 'array_replace_recursive'],
                    true
                )
            ) {
                $mergeForcedSlot = $this->finalizeArrayMergeFamilyCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $sends
                );
                if (null !== $mergeForcedSlot) {
                    $valueSlot = $mergeForcedSlot;
                }
            }
            if (null !== $cfgCallOp) {
                $leadingConstFuncPreludeEmit = [];
                $leadingConstFuncPreludeSlot = $this->finalizeLeadingConstFetchFuncCallPreludeCallArgSlot(
                    $block,
                    $cfgCallOp,
                    (int) $argIndex,
                    $leadingConstFuncPreludeEmit
                );
                if ([] !== $leadingConstFuncPreludeEmit) {
                    $sends = array_merge($sends, $leadingConstFuncPreludeEmit);
                }
                if (null !== $leadingConstFuncPreludeSlot) {
                    $valueSlot = $leadingConstFuncPreludeSlot;
                }
            }
            if (null !== $cfgCallOp) {
                $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                    $block,
                    $cfgCallOp,
                    false
                );
                if (
                    null !== $immediatePropertySlot
                    && $this->callArgIsDeadInlineTemporary($callArgOperand ?? $arg)
                ) {
                    $valueSlot = $immediatePropertySlot;
                }
            }
            $syncedFinalArgSlot = $this->resolveSyncedCoalesceFuncCallArgSlot($callArgOperand ?? $arg);
            if (null !== $syncedFinalArgSlot) {
                $valueSlot = (string) $syncedFinalArgSlot;
            }
            if (
                null !== $cfgCallOp
                && 0 === (int) $argIndex
                && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
                && $this->callArgIsDeadInlineTemporary($cfgCallOp->args[0] ?? null)
                && null !== $block->orig
            ) {
                $hoistedScalarArgSlot = $this->slotForVarExportHoistedScalarConstArgZero(
                    $block,
                    $cfgCallOp,
                    $sends
                );
                if (null !== $hoistedScalarArgSlot) {
                    $valueSlot = $hoistedScalarArgSlot;
                }
            }
            if (null !== $cfgCallOp && null !== $block->orig) {
                $bitmaskArgProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                $bitmaskSlot = $this->tryResolveInlineBitmaskCallArgSlot(
                    $bitmaskArgProbe,
                    $block,
                    $sends,
                    $cfgCallOp,
                    (int) $argIndex
                );
                if (null === $bitmaskSlot) {
                    $trailingBitmaskArgIndex = $this->trailingNonEmbeddedCallArgIndex($cfgCallOp);
                    $bitmaskCallIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
                    if (\is_int($bitmaskCallIndex) && $bitmaskCallIndex > 0) {
                        $bitmaskImmediate = $block->orig->children[$bitmaskCallIndex - 1] ?? null;
                        if ($bitmaskImmediate instanceof Op\Expr\Assign) {
                            $hoistedRhs = $bitmaskCallIndex > 1
                                ? ($block->orig->children[$bitmaskCallIndex - 2] ?? null)
                                : null;
                            if (
                                $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseOr
                                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseAnd
                                || $hoistedRhs instanceof Op\Expr\BinaryOp\BitwiseXor
                            ) {
                                $bitmaskImmediate = $hoistedRhs;
                            } else {
                                $bitmaskImmediate = $bitmaskImmediate->expr;
                            }
                        }
                        if (
                            (int) $argIndex === $trailingBitmaskArgIndex
                            && (
                                $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseOr
                                || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseAnd
                                || $bitmaskImmediate instanceof Op\Expr\BinaryOp\BitwiseXor
                            )
                            && (
                                $this->callArgIsDeadInlineTemporary($bitmaskArgProbe)
                                || $this->callArgIsAssignInCallOperand($bitmaskArgProbe)
                            )
                            && !$this->callArgOperandExpectsArrayProducer($bitmaskArgProbe)
                        ) {
                            $namedDest = $this->slotForHoistedAssignInCallNamedDest($block, $cfgCallOp);
                            if (null !== $namedDest) {
                                $bitmaskSlot = $namedDest;
                            } elseif (null === $block->slotForOperand($bitmaskImmediate->result)) {
                                foreach ($this->compileExpr($bitmaskImmediate, $block) as $op) {
                                    $sends[] = $op;
                                }
                                $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                            } else {
                                $bitmaskSlot = $block->slotForOperand($bitmaskImmediate->result);
                            }
                        }
                    }
                }
                if (
                    null !== $bitmaskSlot
                    && (int) $argIndex === $this->trailingNonEmbeddedCallArgIndex($cfgCallOp)
                ) {
                    $valueSlot = (string) $bitmaskSlot;
                }
            }
            if (null !== $nullLiteralCallArgSlot) {
                $valueSlot = $nullLiteralCallArgSlot;
            }
            // Last word to the exact argument->producer link (#23354). Every heuristic above resolves
            // a hoisted argument from the statement before the call, which is only ever the TRAILING
            // argument's producer; php-cfg records the real producer as the argument temporary's sole
            // writer, so this is the one mapping that is right by construction rather than by shape.
            $exactSlot = $this->exactHoistedCallArgProducerSlot($block, $cfgCallOp, (int) $argIndex, $sends);
            if (null !== $exactSlot) {
                $valueSlot = $exactSlot;
            }
            // Bare named locals ($x as call arg): CV assign-dest must win over later heuristics
            // that re-bind the call-site clone Temporary to a fresh empty slot (#23893, re-#23354).
            $bareLocalProbe = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                $bareLocalProbe instanceof Operand
                && null !== Block::resolveVariableName($bareLocalProbe)
                && !$this->callArgIsDeadInlineTemporary($bareLocalProbe)
            ) {
                $bareNamedDest = $block->slotForNamedAssignDest($bareLocalProbe);
                if (null !== $bareNamedDest) {
                    $valueSlot = $this->resolveNamedAssignCallArgSlot(
                        $block,
                        (int) $bareNamedDest,
                        $calleeName,
                        (int) $argIndex,
                        $bareLocalProbe
                    );
                } elseif (null === $valueSlot) {
                    $valueSlot = $this->compileOperand($bareLocalProbe, $block, true);
                }
            }
            // [...new ArrayIterator([...])] as call arg: nested ctor Array_ slot may win over
            // the spread INIT_ARRAY that sits after FUNCCALL_EXEC_RETURN (#24645).
            $callArgProbeFinal = ($cfgCallOp->args[(int) $argIndex] ?? null) ?? $arg;
            if (
                null !== $cfgCallOp
                && $callArgProbeFinal instanceof Operand
                && $this->callArgIsDeadInlineTemporary($callArgProbeFinal)
                && (
                    $this->callArgOperandExpectsArrayProducer($callArgProbeFinal)
                    || $this->callArgIsDeadUnknownOrMixedTemporary($callArgProbeFinal)
                )
            ) {
                $spreadResultSlot = $this->slotForArraySpreadResultAfterLastExecReturn($block, $sends);
                if (null !== $spreadResultSlot) {
                    $valueSlot = $spreadResultSlot;
                }
            }
            // array_merge([1], $x ? [2] : [3]) / twoway(FLAG, 'C' ?: 'D') — non-Phi sibling of
            // ?: must keep Array_/ConstFetch writer slot, not the merge phi (#25337).
            if (null !== $cfgCallOp && \is_array($cfgCallOp->args ?? null)) {
                $ternarySiblingProbe = $cfgCallOp->args[(int) $argIndex] ?? $arg;
                if ($ternarySiblingProbe instanceof Operand) {
                    $ternarySiblingSlot = $this->resolveNonPhiSiblingOfTernaryCallArgSlot(
                        $block,
                        $cfgCallOp,
                        (int) $argIndex,
                        $ternarySiblingProbe,
                        $sends
                    );
                    if (null !== $ternarySiblingSlot) {
                        $valueSlot = $ternarySiblingSlot;
                    }
                }
            }
            $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $valueSlot, $nameSlot, $unpackFlag);
        }

        return $sends;
    }
}
