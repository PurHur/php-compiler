<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Mid call-arg ARG_SEND heuristics after null/merge/property/hoisted preludes:
 * MethodCall inline New enum case / hoisted cast arg0 / error-suppress result /
 * New_ ctor array producer / init-array & keys / FirstClassCallable FCC feeds
 * (#36387 / #36403).
 *
 * Extracted from {@see CompileCallArgSends} so gen-0 split-TU can hollow a smaller
 * Concern TU. `$sends` is by-ref so compileExpr prelude ops stay on the send list.
 * Mirrors php-src Zend/zend_compile.c call-arg send operand wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types and passes
 * string slot ids into OpCode(?int) via coercion; strict_types here TypeErrors.
 */
trait CallArgInlineEnumCastErrorSuppressAndFccSends
{
    /**
     * @param list<OpCode> $sends already-emitted ARG_SENDs (compileExpr may append)
     * @return bool true when an ARG_SEND (and any preludes) were appended — caller continues
     */
    private function tryCompileCallArgInlineEnumCastErrorSuppressAndFccSend(
        mixed $arg,
        int $argIndex,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp,
        array $args,
        mixed $nameSlot,
        mixed $unpackFlag,
        array &$sends
    ): bool {
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
                return true;
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
            return true;
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
            return true;
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
                    return true;
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
                    return true;
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
                return true;
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
                    return true;
                }
                $initArraySlot = $this->slotForInitArrayBeforeCurrentFunccall($block);
                if (null !== $initArraySlot) {
                    $sends[] = new OpCode(OpCode::TYPE_ARG_SEND, $initArraySlot, $nameSlot, $unpackFlag);
                    return true;
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
                return true;
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
                    return true;
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
                                return true;
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
                                return true;
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
                    return true;
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
                return true;
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
                return true;
            }
        }
        return false;
    }
}
