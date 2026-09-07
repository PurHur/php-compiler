<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Null / merge-family named / PropertyFetch / unpack-array / Closure::fromCallable /
 * count-family / array_splice / closure-bind / dead-inline + hoisted const/classconst
 * prelude ARG_SEND paths (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. Keep `$sends` by-ref so unpack-array / compileExpr prelude ops stay on
 * the send list. Mirrors php-src Zend/zend_compile.c call-arg send operand wiring —
 * move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgNullMergePropertyFetchAndHoistedPreludeSends
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (unpack/compileExpr may append)
     * @return OpCode|null ARG_SEND when a mid-early path matched; null to continue heuristics
     */
    private function tryCompileCallArgNullMergePropertyFetchAndHoistedPreludeSend(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        mixed $nameSlot,
        mixed $unpackFlag,
        array $args,
        array &$sends
    ): ?OpCode {
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    (string) $this->registerNullConstantSlot($block, $nullArg),
                    $nameSlot,
                    $unpackFlag
                );
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $mergeNamedValueSlot,
                    $nameSlot,
                    $unpackFlag
                );
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
                            return new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                (string) $propertyFetchArgSlot,
                                $nameSlot,
                                $unpackFlag
                            );
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
                    return new OpCode(
                        OpCode::TYPE_ARG_SEND,
                        (string) $unpackArraySlot,
                        $nameSlot,
                        $unpackFlag
                    );
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
                    return new OpCode(OpCode::TYPE_ARG_SEND, (string) $fccInlineArgSlot, $nameSlot, $unpackFlag);
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
                        return new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            $hoistedArrayCallSlot,
                            $nameSlot,
                            $unpackFlag
                        );
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
            return new OpCode(
                OpCode::TYPE_ARG_SEND,
                $this->compileOperand($arg, $block, true),
                $nameSlot,
                $unpackFlag
            );
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $inlineNewBindArgSlot,
                    $nameSlot,
                    $unpackFlag
                );
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $staticBindClosureSlot,
                    $nameSlot,
                    $unpackFlag
                );
            }
            $staticBindNewThisSlot = $this->slotForStaticClosureBindNewThisArg(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $staticBindNewThisSlot) {
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $staticBindNewThisSlot,
                    $nameSlot,
                    $unpackFlag
                );
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
                        return new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $preludeSlot,
                            $nameSlot,
                            $unpackFlag
                        );
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
                        return new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $this->registerNullConstantSlot(
                                $block,
                                $preludeProducer->result ?? new Operand\Temporary()
                            ),
                            $nameSlot,
                            $unpackFlag
                        );
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
                        return new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $constSlot,
                            $nameSlot,
                            $unpackFlag
                        );
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
                        return new OpCode(
                            OpCode::TYPE_ARG_SEND,
                            (string) $classConstSlot,
                            $nameSlot,
                            $unpackFlag
                        );
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
                            return new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                $constSlot,
                                $nameSlot,
                                $unpackFlag
                            );
                        }
                        $classConstSlot = $block->slotForOperand($immediatePrelude->result);
                        if (null === $classConstSlot) {
                            foreach ($this->compileExpr($immediatePrelude, $block) as $op) {
                                $sends[] = $op;
                            }
                            $classConstSlot = $block->slotForOperand($immediatePrelude->result);
                        }
                        if (null !== $classConstSlot) {
                            return new OpCode(
                                OpCode::TYPE_ARG_SEND,
                                $classConstSlot,
                                $nameSlot,
                                $unpackFlag
                            );
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    (string) $this->registerNullConstantSlot($block, $nullArg),
                    $nameSlot,
                    $unpackFlag
                );
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
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    (string) $hoistedIssetEmptyArgSlot,
                    $nameSlot,
                    $unpackFlag
                );
            }
            $inlineLiteralDimArgSlot = $this->resolveInlineArrayLiteralDimFetchCallArgSlot(
                $block,
                $cfgCallOp,
                (int) $argIndex
            );
            if (null !== $inlineLiteralDimArgSlot) {
                return new OpCode(
                    OpCode::TYPE_ARG_SEND,
                    $inlineLiteralDimArgSlot,
                    $nameSlot,
                    $unpackFlag
                );
            }
        }

        return null;
    }
}
