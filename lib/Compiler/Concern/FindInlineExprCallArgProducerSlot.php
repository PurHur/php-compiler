<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;

use PHPCfg\Op;
use PHPCfg\Operand;

use PHPTypes\Type;

/**
 * Inline expr call-arg producer slot discovery (#36387 / prior #36147).
 *
 * Extracted from {@see FindInlineCallArgProducerSlot} so gen-0 split-TU can
 * hollow a smaller Concern TU ({@see findInlineExprCallArgProducerSlot}).
 *
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_execute.c ZEND_SEND_* adjacent call-arg wiring — move-only.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as FindInlineCallArgProducerSlot).
 */
trait FindInlineExprCallArgProducerSlot
{
    private function findInlineExprCallArgProducerSlot(Operand $arg, Block $block, ?Op $cfgCallOp = null): ?string
    {
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if ($this->callArgIsDeadInlineTemporary($callArg)) {
            $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall($block, $callOp);
            if (null !== $immediatePropertySlot) {
                return $immediatePropertySlot;
            }
        }
        if ($this->headerScalarCallArgMustUseDirectOperand($this->funcCallExprCalleeName($callOp), $argIndex)) {
            return null;
        }
        // Statement-level side-effect calls before f($local) are not inline arg producers (#11093, #11375).
        $namedLocalSlot = $this->namedLocalCallArgSlotIfBound($arg, $block, $callOp, $argIndex);
        if (null !== $namedLocalSlot) {
            return $namedLocalSlot;
        }
        // php-cfg may lower a boolean-producing inline Expr (e.g. `===`) to a distinct arg temp with
        // no dataflow edge, leaving the arg slot empty. Prefer the immediately preceding binary op
        // producer when its inferred type matches the arg (#9030).
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null !== $callIndex && $callIndex > 0) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            $arrayProducerCount = 0;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\Array_) {
                    ++$arrayProducerCount;
                }
            }
            $funcCallProducerCount = 0;
            foreach ($producers as $producer) {
                if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall
                    || $producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall) {
                    ++$funcCallProducerCount;
                }
            }
            if (
                null !== $cfgCallOp
                && $this->hasSiblingMultiArgInlineCallProducers($block, $callOp)
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? null)
                && !$this->nestedFuncCallFeedsDeadInlineCallArgZero($block, $callOp, $argIndex)
            ) {
                // var_dump(f(), g()) after an earlier sibling chain — ordinal wiring is resolveSiblingInlineCallArgProducerSlot (#16254).
                return null;
            }
            if ($arrayProducerCount >= 2 || $funcCallProducerCount >= 2
                || null !== $this->splitLeadingConstFetchWithArrayLiteralCallArg($producers)
                || null !== $this->splitLeadingConstFetchWithFuncCallCallArg($producers)
                || $this->producersAreSiblingCallWithHoistedScalarConstFetch($producers)
                || $this->producersAreSiblingArithmeticWithHoistedScalarConstFetch($producers)
                || $this->producersAreSiblingCallWithHoistedEnumConstFetch($producers)
                || $this->producersIncludeUnaryOffsetWithConstWhence($producers)) {
                $matched = null;
                if (
                    $this->callIncludesNamedParameter($callOp)
                    && isset($callOp->args[$argIndex])
                    && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex])
                ) {
                    $matched = $this->findUnassignedInlineArrayProducerForDeadCallArg(
                        $producers,
                        $callOp,
                        $argIndex,
                        $block
                    );
                }
                if (!$matched instanceof Op\Expr) {
                    $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                }
                if ($matched instanceof Op\Expr\Array_) {
                    $outerArray = $this->matchOutermostNestedInlineArrayProducerForArgZero(
                        $producers,
                        $argIndex,
                        \count($callOp->args),
                        \count($producers)
                    );
                    if (null !== $outerArray) {
                        $matched = $outerArray;
                    }
                }
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $slot = $this->slotForInlineCallArgProducerResult(
                        $block,
                        $matched,
                        $callOp,
                        $block->orig->children
                    );
                    if (null !== $slot) {
                        return $slot;
                    }
                }
            }
            // array_reverse([...], true) hoists Array_/ConstFetch preludes — still one nested FuncCall (#14042).
            if (1 === $funcCallProducerCount) {
                $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($matched instanceof Op\Expr\FuncCall || $matched instanceof Op\Expr\NsFuncCall
                    || $matched instanceof Op\Expr\StaticCall || $matched instanceof Op\Expr\MethodCall) {
                    $producerIndex = null;
                    foreach ($block->orig->children as $i => $child) {
                        if ($child === $matched) {
                            $producerIndex = $i;
                            break;
                        }
                    }
                    if (
                        null !== $producerIndex
                        && null !== $callIndex
                        && $this->statementLevelFuncCallBeforeHoistedSiblingChain(
                            $producerIndex,
                            $callIndex,
                            $block->orig->children
                        )
                    ) {
                        $matched = null;
                    }
                    if (
                        null !== $matched
                        && null !== $producerIndex
                        && null !== $callIndex
                        && $this->isAdjacentNestedFuncCallProducer($matched, $callOp, $producerIndex, $callIndex)
                    ) {
                        $slot = $this->slotForNestedFuncCallArrayConsumerProducer(
                            $block,
                            $matched,
                            $callOp,
                            $producerIndex,
                            $argIndex
                        );
                        if (null === $slot) {
                            $slot = $block->slotForOperand($matched->result);
                        }
                        if (null === $slot) {
                            foreach ($this->compileExpr($matched, $block) as $op) {
                                $block->addOpCode($op);
                            }
                            $slot = $this->slotForNestedFuncCallArrayConsumerProducer(
                                $block,
                                $matched,
                                $callOp,
                                $producerIndex,
                                $argIndex
                            ) ?? $block->slotForOperand($matched->result);
                        }
                        if (null !== $slot) {
                            return (string) $slot;
                        }
                    }
                }
            }
            if ($this->callIncludesNamedParameter($callOp) && [] !== $producers) {
                $matched = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($matched instanceof Op\Expr) {
                    if (null === $block->slotForOperand($matched->result)) {
                        foreach ($this->compileExpr($matched, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $slot = $block->slotForOperand($matched->result);
                    if (null !== $slot) {
                        return (string) $slot;
                    }
                }
            }
            $ownProducer = $this->inlineProducerForHoistedCallArgIndex(
                $block->orig->children,
                $callOp,
                $callIndex,
                (int) $argIndex
            );
            if (null !== $ownProducer) {
                if (null === $block->slotForOperand($ownProducer->result)) {
                    foreach ($this->compileExpr($ownProducer, $block) as $op) {
                        $block->addOpCode($op);
                    }
                }
                $ownSlot = $block->slotForOperand($ownProducer->result);
                if (null !== $ownSlot) {
                    return $ownSlot;
                }
            }
            $prev = $block->orig->children[$callIndex - 1] ?? null;
            if ($prev instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($prev->name);
                if (null !== $name) {
                    $lookup = strtolower($name);
                    $isHoistedScalar = \in_array($lookup, ['true', 'false', 'null'], true)
                        || \PHPCompiler\ext\standard\StdlibConstants::hasCoreIntByName($lookup)
                        || null !== \PHPCompiler\VM\Context::errorReportingConstant($name);
                    if ($isHoistedScalar) {
                        $callArg = $callOp->args[$argIndex] ?? null;
                        $callArgs = $callOp->args;
                        $isLastArg = \is_array($callArgs) && $argIndex === \count($callArgs) - 1;
                        if (null !== $callArg && $this->operandsReferToSameVariable($prev->result, $callArg)) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                        // json_decode('...', true, N) — hoisted true feeds assoc (arg 1), not trailing depth (#9489).
                        // Also json_decode(json_encode($d), true) — arg0 is a nested FuncCall dead temp (#24137).
                        $jsonDecodeAssocArg = 'json_decode' === strtolower($this->resolveCfgFuncCallName($callOp) ?? '')
                            && 1 === $argIndex
                            && $this->callArgIsDeadInlineTemporary($callArg)
                            && (
                                $this->isEmbeddedCallLiteralArg($callOp->args[0] ?? null)
                                || $this->callArgIsDeadInlineTemporary($callOp->args[0] ?? null)
                            );
                        // Hoisted true/false/null only feeds the trailing call arg (#9140, #9660).
                        if (
                            null !== $callArg
                            && ($isLastArg || $jsonDecodeAssocArg)
                            && !$this->operandsReferToSameVariable($prev->result, $callArg)
                            && \in_array($lookup, ['true', 'false', 'null'], true)
                        ) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                        // Hoisted SORT_* / PHP_* / E_USER_* int constants (incl. zero-valued SORT_REGULAR) (#9462, #9548, #11526).
                        if (
                            null !== $callArg
                            && $isLastArg
                            && (
                                \PHPCompiler\ext\standard\StdlibConstants::hasCoreIntByName($lookup)
                                || null !== \PHPCompiler\VM\Context::errorReportingConstant($name)
                            )
                        ) {
                            $slot = $block->slotForOperand($prev->result);
                            if (null !== $slot) {
                                return (string) $slot;
                            }
                            $vm = $this->tryFoldGlobalConstFetch($prev);
                            if (null !== $vm) {
                                return (string) $block->registerConstant($arg, $vm);
                            }
                        }
                    }
                }
            }
            $argRoot = $this->unwrapOperandChain($arg);
            $callArg = $callOp->args[$argIndex] ?? null;
            // Trailing scalar/flag prelude (BitwiseOr, Plus, UnaryMinus, Cast, …) — only last arg (#18523, #19735, #19738).
            // Do not steal earlier dead-temp New_ args (multi-arg ctor start + options).
            if (
                (
                    $this->isArithmeticInlineCallArgProducer($prev)
                    || $prev instanceof Op\Expr\UnaryMinus
                    || $prev instanceof Op\Expr\UnaryPlus
                    || $prev instanceof Op\Expr\BitwiseNot
                    || $prev instanceof Op\Expr\Cast
                )
                && null !== $callArg
                && $this->callArgIsDeadInlineTemporary($callArg)
                && !$this->callArgOperandExpectsArrayProducer($callArg)
                && $argIndex === $this->trailingNonEmbeddedCallArgIndex($callOp)
            ) {
                if (null === $block->slotForOperand($prev->result)) {
                    foreach ($this->compileExpr($prev, $block) as $op) {
                        $block->addOpCode($op);
                    }
                }
                $slot = $block->slotForOperand($prev->result);
                if (null !== $slot) {
                    return $slot;
                }
            }
            if (($prev instanceof Op\Expr\BinaryOp || $prev instanceof Op\Expr\InstanceOf_ || $prev instanceof Op\Expr\In_)
                && null !== $prev->result
                && (
                    $prev instanceof Op\Expr\In_
                    || $this->isComparisonInlineCallArgProducer($prev)
                    || (
                        null !== $argRoot->type
                        && null !== $prev->result->type
                        && $argRoot->type->type === $prev->result->type->type
                        && in_array(
                            $argRoot->type->type,
                            [Type::TYPE_BOOLEAN, Type::TYPE_LONG, Type::TYPE_ARRAY],
                            true
                        )
                    )
                )
            ) {
                if (null === $block->slotForOperand($prev->result)) {
                    foreach ($this->compileExpr($prev, $block) as $op) {
                        $block->addOpCode($op);
                    }
                }
                $slot = $block->slotForOperand($prev->result);
                if (null !== $slot) {
                    return $slot;
                }
            }
            if ($prev instanceof Op\Expr\Assign && null !== $prev->result) {
                $callArg = $callOp->args[$argIndex] ?? null;
                if (
                    null !== $callArg
                    && null !== $prev->var
                    && $this->operandsReferToSameVariable($prev->var, $callArg)
                ) {
                    $slot = $block->slotForOperand($prev->result);
                    if (null !== $slot) {
                        return $slot;
                    }
                }
            }
            // var_export(require_once $f) / var_export(include $f, true) — adjacent Include_/Eval_ (#25852).
            $includeProducer = null;
            if ($prev instanceof Op\Expr\Include_ || $prev instanceof Op\Expr\Eval_) {
                $includeProducer = $prev;
            } elseif (
                0 === $argIndex
                && $this->isHoistedScalarConstFetchImmediatelyBeforeCall($prev)
                && $callIndex >= 2
            ) {
                $beforeConst = $block->orig->children[$callIndex - 2] ?? null;
                if ($beforeConst instanceof Op\Expr\Include_ || $beforeConst instanceof Op\Expr\Eval_) {
                    $includeProducer = $beforeConst;
                }
            }
            if (
                null !== $includeProducer
                && null !== ($callOp->args[$argIndex] ?? null)
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex])
            ) {
                if (null === $block->slotForOperand($includeProducer->result)) {
                    foreach ($this->compileExpr($includeProducer, $block) as $op) {
                        $block->addOpCode($op);
                    }
                }
                $slot = $block->slotForOperand($includeProducer->result);
                if (null !== $slot) {
                    return (string) $slot;
                }
            }
        }
        $coalesceArg = $this->findCoalesceStmtForCallArg($arg, $block);
        if (null !== $coalesceArg) {
            $coalesceSlot = $this->compileCallArgCoalesceSlot($arg, $block, $callOp, $argIndex);
            if (null !== $coalesceSlot) {
                return $coalesceSlot;
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
        if (
            ($producer instanceof Op\Expr\ConstFetch || $producer instanceof Op\Expr\ClassConstFetch)
            && $this->shouldRemapHoistedConstFetchToAdjacentNestedCall($producer, $callOp, $argIndex, $block)
        ) {
            // probe('label', in_array(..., g(), true)) — ConstFetch is inner callee arg, not this slot (#14237).
            $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $callOp, $argIndex);
            if (null !== $adjacentSlot) {
                return $adjacentSlot;
            }
        }
        if (null === $producer) {
            $adjacentSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $callOp, $argIndex);
            if (null !== $adjacentSlot) {
                return $adjacentSlot;
            }
            $classConstSlot = $this->slotForHoistedClassConstFetchCallArg($arg, $block, $callOp, $argIndex);
            if (null !== $classConstSlot) {
                return $classConstSlot;
            }
            $logicalPhi = $this->logicalShortCircuitPhiMergeSlot($block);
            if (
                null !== $logicalPhi
                && null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? null)
                && \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['exit', 'die'], true)
            ) {
                return (string) $logicalPhi;
            }
            $exitPhi = $this->resolveExitLogicalShortCircuitCallArgSlot($block);
            if (
                null !== $exitPhi
                && null !== $cfgCallOp
                && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? null)
                && \in_array(strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''), ['exit', 'die'], true)
            ) {
                return $exitPhi;
            }

            return $this->slotForMatchResultDeadCallArg($arg, $block, $cfgCallOp);
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && null !== $block->orig
        ) {
            $nestedProducerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (\is_int($nestedProducerIndex)) {
                $callArgProbe = $callOp->args[$argIndex] ?? null;
                if (
                    $callArgProbe instanceof Operand
                    && $this->callArgOperandExpectsArrayProducer($callArgProbe)
                ) {
                    if (null === $block->slotForOperand($producer->result)) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $block->addOpCode($op);
                        }
                    }
                    $nestedArrayConsumerSlot = $this->slotForNestedFuncCallArrayConsumerProducer(
                        $block,
                        $producer,
                        $callOp,
                        $nestedProducerIndex,
                        $argIndex
                    );
                    if (null !== $nestedArrayConsumerSlot) {
                        return $nestedArrayConsumerSlot;
                    }
                }
            }
        }
        if ($producer instanceof Op\Expr\PropertyFetch) {
            $coalesceSlot = $this->resolvePropertyFetchCoalesceCallArgSlot(
                $producer,
                $callOp,
                $arg,
                $block,
                $argIndex
            );
            if (null !== $coalesceSlot) {
                return (string) $coalesceSlot;
            }
        }
        if ($producer instanceof Op\Expr\ArrayDimFetch) {
            $coalesceSlot = $this->resolveArrayDimFetchCoalesceCallArgSlot(
                $producer,
                $callOp,
                $arg,
                $block,
                $argIndex
            );
            if (null !== $coalesceSlot) {
                return (string) $coalesceSlot;
            }
        }
        $producerSlot = $block->slotForOperand($producer->result);
        if (
            null === $producerSlot
            && $producer instanceof Op\Expr\Empty_
            && !$this->emptyExprLoweringEmitted($block, $producer)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (
            null === $producerSlot
            && $producer instanceof Op\Expr\Isset_
            && !$this->issetExprLoweringEmitted($block, $producer)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\ConstFetch) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\BinaryOp) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\Cast) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\Eval_) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\Include_) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\New_) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $this->slotForInlineNewProducer($block, $producer);
        }
        if (null === $producerSlot && $producer instanceof Op\Expr\MagicScriptConst) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $block->slotForOperand($producer->result);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall)
        ) {
            $producerSlot = $this->slotForNullsafeResult($block, $producer);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\NullsafePropertyFetch
                || $producer instanceof Op\Expr\NullsafeMethodCall)
        ) {
            foreach ($this->compileExpr($producer, $block) as $op) {
                $block->addOpCode($op);
            }
            $producerSlot = $this->slotForNullsafeResult($block, $producer);
        }
        if (
            null === $producerSlot
            && ($producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\StaticCall
                || $producer instanceof Op\Expr\MethodCall)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (
                null !== $callIndex
                && null !== $producerIndex
                && (
                    $this->isNestedCallArgProducerForConsumer(
                        $producer,
                        $callOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                    || $this->isSiblingMultiArgFuncCallProducer(
                        $producer,
                        $callOp,
                        $producerIndex,
                        $callIndex,
                        $block->orig->children
                    )
                )
            ) {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $block->addOpCode($op);
                }
                $producerSlot = null;
                if (null !== $producerIndex) {
                    $producerSlot = $this->slotForNestedFuncCallArrayConsumerProducer(
                        $block,
                        $producer,
                        $callOp,
                        $producerIndex,
                        $argIndex
                    );
                }
                if (null === $producerSlot) {
                    if ($producer instanceof Op\Expr\MethodCall || $producer instanceof Op\Expr\StaticCall) {
                        $methodExecSlot = $this->slotForSiblingMethodCallProducerExecReturn(
                            $block,
                            $producer,
                            $callOp,
                            $block->orig->children
                        );
                        if (null !== $methodExecSlot) {
                            $producerSlot = (int) $methodExecSlot;
                        }
                    }
                    if (null === $producerSlot) {
                        $producerSlot = $block->slotForOperand($producer->result);
                    }
                }
            }
        }
        if (null === $producerSlot) {
            if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                $producerSlot = $this->slotForInlineClosureProducer($producer, $block);
            } elseif ($producer instanceof Op\Expr\FirstClassCallable) {
                $producerSlot = $this->slotForInlineFirstClassCallableProducer($producer, $block);
            }
            if (null === $producerSlot) {
                return null;
            }
        }
        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && !$this->namedCallArgMayUseFuncCallProducerResult($producer, $arg)
        ) {
            return null;
        }
        if ($producer instanceof Op\Expr\Empty_) {
            return $producerSlot;
        }
        if ($producer instanceof Op\Expr\Isset_) {
            return $producerSlot;
        }
        if ($producer instanceof Op\Expr\ArrowFunction || $producer instanceof Op\Expr\Closure) {
            if (null === $producerSlot) {
                foreach ($this->compileExpr($producer, $block) as $op) {
                    $block->addOpCode($op);
                }
                $producerSlot = $block->slotForOperand($producer->result);
            }
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }
        if ($producer instanceof Op\Expr\FirstClassCallable) {
            $producerSlot = $this->slotForInlineFirstClassCallableProducer($producer, $block);
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }
        // php-cfg uses distinct result/arg temps for hoisted inline producers (#8766, #8561, #9136).
        if (
            $producer instanceof Op\Expr\Assign
            || $producer instanceof Op\Expr\BinaryOp
            || $producer instanceof Op\Expr\ConstFetch
            || $producer instanceof Op\Expr\ClassConstFetch
            || $producer instanceof Op\Expr\InstanceOf_
            || $producer instanceof Op\Expr\Cast
            || $producer instanceof Op\Expr\MagicScriptConst
            || $producer instanceof Op\Expr\FirstClassCallable
            || $producer instanceof Op\Expr\New_
            || $producer instanceof Op\Expr\UnaryMinus
            || $producer instanceof Op\Expr\UnaryPlus
            || $producer instanceof Op\Expr\BitwiseNot
            || $producer instanceof Op\Expr\BooleanNot
            || $producer instanceof Op\Expr\PostInc
            || $producer instanceof Op\Expr\PreInc
            || $producer instanceof Op\Expr\PostDec
            || $producer instanceof Op\Expr\PreDec
        ) {
            return $producerSlot;
        }
        $argSlot = $this->compileOperand($arg, $block, false);
        if (null === $argSlot) {
            return $producerSlot;
        }
        if ($producerSlot === $argSlot) {
            return $producerSlot;
        }
        if ($this->operandsReferToSameVariable($producer->result, $arg)) {
            if ($this->funcCallExprByRefArgMatchesOperand($producer, $arg)) {
                return null;
            }

            return $producerSlot;
        }
        // php-cfg uses distinct result/arg temps for `$f($a[0])` (#8814, zend_compile.c).
        if ($producer instanceof Op\Expr\ArrayDimFetch) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (null !== $callIndex && null !== $producerIndex && $producerIndex === $callIndex - 1) {
                return $producerSlot;
            }
        }
        if (
            $producer instanceof Op\Expr\PropertyFetch
            || $producer instanceof Op\Expr\StaticPropertyFetch
            || $producer instanceof Op\Expr\NullsafePropertyFetch
            || $producer instanceof Op\Expr\NullsafeMethodCall
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (null !== $callIndex && null !== $producerIndex && $producerIndex === $callIndex - 1) {
                return $producerSlot;
            }

            return $producerSlot;
        }
        // php-cfg `var_dump(f(), g())` / `var_dump($o->f(), $o->g())` — sibling call producers
        // with distinct result/arg temps (#9351, zend_compile.c call-arg evaluation order).
        if (
            null !== $producerSlot
            && ($producer instanceof Op\Expr\FuncCall
                || $producer instanceof Op\Expr\NsFuncCall
                || $producer instanceof Op\Expr\MethodCall
                || $producer instanceof Op\Expr\StaticCall)
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $producerIndex < $callIndex
                && !$this->isNestedCallArgProducerForConsumer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
                && !$this->isSiblingMultiArgFuncCallProducer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return $producerSlot;
            }
        }
        // php-cfg `f(g())` uses distinct result/arg temporaries (#8561, #7075).
        if (
            $producer instanceof Op\Expr\FuncCall
            || $producer instanceof Op\Expr\NsFuncCall
            || $producer instanceof Op\Expr\StaticCall
            || $producer instanceof Op\Expr\MethodCall
        ) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
            $producerIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $producer, $block->orig);
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $this->isNestedCallArgProducerForConsumer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
            ) {
                return $producerSlot;
            }
            if (
                null !== $callIndex
                && null !== $producerIndex
                && $this->isSiblingMultiArgFuncCallProducer(
                    $producer,
                    $callOp,
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                )
                && $this->siblingMultiArgFuncCallProducerTargetArgIndex(
                    $producerIndex,
                    $callIndex,
                    $block->orig->children
                ) === $argIndex
            ) {
                return $producerSlot;
            }
            if (
                ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
                && !$this->inlineCallArgProducerFeedsConsumer($producer, $callOp)
            ) {
                if (
                    null === $callIndex
                    || null === $producerIndex
                    || (
                        !$this->isNestedCallArgProducerForConsumer($producer, $callOp, $producerIndex, $callIndex, $block->orig->children)
                        && !$this->isSiblingMultiArgFuncCallProducer(
                            $producer,
                            $callOp,
                            $producerIndex,
                            $callIndex,
                            $block->orig->children
                        )
                        && !$this->operandsReferToSameVariable($producer->result, $arg)
                    )
                ) {
                    return null;
                }

                return $producerSlot;
            }
        }

        if (
            ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall)
            && $this->inlineCallArgProducerFeedsConsumer($producer, $callOp)
        ) {
            if (null === $producerSlot) {
                $producerSlot = $this->resolveAdjacentNestedFuncCallArgSlot($block, $callOp, $argIndex);
            }
            if (null !== $producerSlot) {
                return $producerSlot;
            }
        }

        return $this->slotForMatchResultDeadCallArg($arg, $block, $cfgCallOp);
    }
}
