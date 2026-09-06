<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Inline closure / first-class-callable call-arg slot resolvers (#36387 / #36403).
 *
 * Extracted from {@see SlotForCallArgResolvers} so gen-0 split-TU can hollow that
 * Concern TU ({@see slotForRecentClosureCallArg} through
 * {@see resolvePrecedingClosureCallArgSlot}). Complements property/method/init-array
 * peers that remain on the hub.
 *
 * Mirrors php-src Zend/zend_compile.c first-class callable and Closure argument
 * send wiring (zend_compile_func_cstring / FROM_CALLABLE) — move-only; no behavior
 * change intended.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as SlotForCallArgResolvers).
 */
trait SlotForInlineClosureAndFirstClassCallableCallArgResolvers
{
    /** Last TYPE_CLOSURE before the current call — php-cfg dead arg temp vs inline arrow fn (#11586). */
    private function slotForRecentClosureCallArg(Block $block): ?string
    {
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                return (string) $op->arg1;
            }
        }

        return null;
    }

    /** Resolve assign.result slot from emitted TYPE_ASSIGN when cfg temps lack scope bindings (#5644). */
    private function slotForEmittedAssignResultSlot(Block $block, Op\Expr\Assign $assign): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        $assignOrdinal = 0;
        $targetOrdinal = null;
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Assign) {
                if ($child === $assign) {
                    $targetOrdinal = $assignOrdinal;
                    break;
                }
                ++$assignOrdinal;
            }
        }
        if (null === $targetOrdinal) {
            return null;
        }
        $seen = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($seen === $targetOrdinal) {
                return (int) $op->arg1;
            }
            ++$seen;
        }

        return null;
    }

    /** `$cmp = fn(...); f(..., $cmp)` — use the named local, not the dead closure temp (#5644). */
    private function slotForNamedClosureLocalFromProducer(Op\Expr $producer, Block $block): ?int
    {
        if (null === $block->orig) {
            return null;
        }
        if (
            !$producer instanceof Op\Expr\ArrowFunction
            && !$producer instanceof Op\Expr\Closure
        ) {
            return null;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr\Assign) {
                continue;
            }
            if (!$this->assignExprMatchesClosureProducer($child->expr, $producer)) {
                continue;
            }
            if (!$this->exprDerivesFromClosure($child->expr)) {
                continue;
            }
            if (null !== $child->result) {
                $namedSlot = $block->slotForOperand($child->result);
                if (null !== $namedSlot) {
                    return (int) $namedSlot;
                }
            }
            $namedSlot = $block->slotForOperand($child->var);
            if (null !== $namedSlot && $block->isNamedVariableSlot((int) $namedSlot)) {
                return (int) $namedSlot;
            }
        }

        return null;
    }

    private function slotForInlineClosureProducer(Op\Expr $producer, Block $block): ?int
    {
        if (null === $producer->result) {
            return null;
        }
        $namedLocal = $this->slotForNamedClosureLocalFromProducer($producer, $block);
        if (null !== $namedLocal) {
            return $namedLocal;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (
                OpCode::TYPE_STATICCALL_INIT === $op->type
                || OpCode::TYPE_FUNCCALL_INIT === $op->type
            ) {
                break;
            }
            if (OpCode::TYPE_CLOSURE === $op->type) {
                $destSlot = (int) $op->arg1;
                $destOperand = $block->operandForScopeSlot($destSlot);
                if (
                    null !== $destOperand
                    && $this->operandsReferToSameVariable($destOperand, $producer->result)
                ) {
                    return $destSlot;
                }
            }
        }
        foreach ($this->compileExpr($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Resolve VM slot for a hoisted inline first-class callable call-arg producer (#9769, zend_compile.c). */
    private function slotForInlineFirstClassCallableProducer(
        Op\Expr\FirstClassCallable $producer,
        Block $block
    ): ?int {
        if (null === $producer->result) {
            return null;
        }
        $slot = $block->slotForOperand($producer->result);
        if (null !== $slot) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FROM_CALLABLE !== $op->type) {
                continue;
            }
            $destSlot = (int) $op->arg1;
            $destOperand = $block->operandForScopeSlot($destSlot);
            if (
                null !== $destOperand
                && $this->operandsReferToSameVariable($destOperand, $producer->result)
            ) {
                return $destSlot;
            }
        }
        foreach ($this->compileFirstClassCallable($producer, $block) as $op) {
            $block->addOpCode($op);
        }

        return $block->slotForOperand($producer->result);
    }

    /** Inline `E::A->m(...)` call args must send the Closure result, not enum-case prefetch slots (#9769). */
    private function resolveInlineFirstClassCallableCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        ?int $knownArgIndex = null
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callOp = $cfgCallOp;
        $argIndex = $knownArgIndex;
        if (null === $knownArgIndex) {
            $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
            if (null !== $callSite) {
                [$callOp, $foundArgIndex] = $callSite;
                $argIndex = $foundArgIndex;
            }
        }
        if (null === $argIndex) {
            return null;
        }
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $funcName = $this->resolveInlineCallArgFuncName($callOp);
        $callbackArgIndex = $this->inlineClosureArrayPairCallbackArgIndex($funcName);
        if ($callbackArgIndex >= 0 && 2 === \count($callOp->args) && $argIndex !== $callbackArgIndex) {
            return null;
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $funcName
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }
        $leadingFcc = null;
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null !== $callIndex) {
            for ($i = $callIndex - 1; $i >= 0; --$i) {
                // php-cfg children can be sparse (undefined keys) — skip holes (#36387 Doctor).
                $prev = $block->orig->children[$i] ?? null;
                if (!$prev instanceof Op) {
                    continue;
                }
                if ($prev instanceof Op\Expr\FirstClassCallable) {
                    $leadingFcc = $prev;
                    break;
                }
                if ($prev instanceof Op\Expr\Assign) {
                    if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                        break;
                    }
                    continue;
                }
                if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                    continue;
                }
                if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                    break;
                }
            }
        }
        if ($leadingFcc instanceof Op\Expr\FirstClassCallable) {
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $leadingFcc,
                $callOp->args,
                $argIndex,
                $funcName
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }
        if (1 === count($callOp->args)) {
            $last = $producers[\count($producers) - 1] ?? null;
            if ($last instanceof Op\Expr\FirstClassCallable) {
                return $this->slotForInlineFirstClassCallableProducer($last, $block);
            }
        }
        if (0 === $this->inlineClosureArrayPairCallbackArgIndex($funcName) && 0 === $argIndex) {
            for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
                $scanOp = $block->opCodes[$i];
                if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                    break;
                }
                if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                    return (int) $scanOp->arg1;
                }
            }
        }

        return null;
    }

    /** StaticCall inline closure first arg — match hoisted Closure producer to TYPE_CLOSURE slot (#3673). */
    private function resolveInlineClosureCallArgSlot(
        Operand $arg,
        Block $block,
        ?Op $cfgCallOp,
        ?string $calleeName = null
    ): ?int {
        if (null === $block->orig || null === $cfgCallOp) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            $argRoot = $this->unwrapOperandChain($arg);
            if ($argRoot instanceof Op\Expr\ArrowFunction || $argRoot instanceof Op\Expr\Closure) {
                return $this->slotForInlineClosureProducer($argRoot, $block);
            }

            return null;
        }
        [$callOp, $argIndex] = $callSite;
        if (!property_exists($callOp, 'args') || !is_array($callOp->args)) {
            return null;
        }
        $callArg = $callOp->args[$argIndex] ?? null;
        if (null !== $callArg && $this->isNamedVariableOperand($callArg)) {
            return null;
        }
        if (null !== $callArg) {
            $callArgRoot = $this->unwrapOperandChain($callArg);
            if ($callArgRoot instanceof Op\Expr\ArrowFunction || $callArgRoot instanceof Op\Expr\Closure) {
                $directSlot = $this->slotForInlineClosureProducer($callArgRoot, $block);
                if (null !== $directSlot) {
                    return $directSlot;
                }
            }
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
        $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block, $calleeName);
        if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
            return $this->slotForInlineClosureProducer($producer, $block);
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\Closure && !$candidate instanceof Op\Expr\ArrowFunction) {
                continue;
            }
            if (null !== $this->matchSingleClosureInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $this->resolveInlineCallArgFuncName($callOp, $calleeName)
            )) {
                return $this->slotForInlineClosureProducer($candidate, $block);
            }
        }
        foreach ($producers as $candidate) {
            if (!$candidate instanceof Op\Expr\FirstClassCallable) {
                continue;
            }
            $fccMatch = $this->matchSingleFirstClassCallableInlineProducer(
                $candidate,
                $callOp->args,
                $argIndex,
                $this->resolveInlineCallArgFuncName($callOp)
            );
            if (null !== $fccMatch) {
                return $this->slotForInlineFirstClassCallableProducer($fccMatch, $block);
            }
        }

        return null;
    }

    /**
     * Inline fn()/function() callback args with trailing literal flags (#10232, #9154).
     */
    private function resolvePrecedingClosureCallArgSlot(
        Op $cfgCallOp,
        int $argIndex,
        Block $block,
        ?string $calleeName = null
    ): ?int {
        if (null === $block->orig || !property_exists($cfgCallOp, 'args') || !is_array($cfgCallOp->args)) {
            return null;
        }
        $callArgs = $cfgCallOp->args;
        $callArg = $callArgs[$argIndex] ?? null;
        if (null !== $callArg && $this->isNamedVariableOperand($callArg)) {
            return null;
        }
        if ($this->isEmbeddedCallLiteralArg($callArg)) {
            return null;
        }
        if (
            \count($callArgs) < 2
            && !$this->cfgCallAcceptsSingleInlineClosureCallback($cfgCallOp)
        ) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $cfgCallOp);
        $callbackProducer = null;
        foreach ($producers as $candidate) {
            if ($candidate instanceof Op\Expr\ArrowFunction
                || $candidate instanceof Op\Expr\Closure
                || $candidate instanceof Op\Expr\FirstClassCallable) {
                $callbackProducer = $candidate;
                break;
            }
        }
        if (null === $callbackProducer) {
            $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $prev = $block->orig->children[$i] ?? null;
                    if (!$prev instanceof Op) {
                        continue;
                    }
                    if ($prev instanceof Op\Expr\ArrowFunction
                        || $prev instanceof Op\Expr\Closure
                        || $prev instanceof Op\Expr\FirstClassCallable) {
                        $callbackProducer = $prev;
                        break;
                    }
                    if ($prev instanceof Op\Expr\Assign) {
                        if ($this->assignPrecedesAndFeedsInlineCallChain($prev, $i, $callIndex, $block->orig->children)) {
                            break;
                        }
                        continue;
                    }
                    if ($prev instanceof Op\Expr\FuncCall || $prev instanceof Op\Expr\NsFuncCall) {
                        continue;
                    }
                    if (!$prev instanceof Op\Expr || !$this->isInlineExprCallArgProducer($prev)) {
                        break;
                    }
                }
            }
        }
        if (null === $callbackProducer) {
            return null;
        }
        $funcName = $this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName);
        $matched = $callbackProducer instanceof Op\Expr\FirstClassCallable
            ? $this->matchSingleFirstClassCallableInlineProducer(
                $callbackProducer,
                $callArgs,
                $argIndex,
                $funcName
            )
            : $this->matchSingleClosureInlineProducer(
                $callbackProducer,
                $callArgs,
                $argIndex,
                $funcName
            );
        if (null === $matched) {
            $matched = $this->matchInlineCallArgProducer($producers, $callArgs, $argIndex, $cfgCallOp, $block, $calleeName);
            if ($matched !== $callbackProducer) {
                return null;
            }
        }
        if ($callbackProducer instanceof Op\Expr\FirstClassCallable) {
            $fccSlot = $this->slotForInlineFirstClassCallableProducer($callbackProducer, $block);
            if (null !== $fccSlot) {
                return $fccSlot;
            }
        } else {
            $slot = $this->slotForInlineClosureProducer($callbackProducer, $block);
            if (null !== $slot) {
                return $slot;
            }
        }
        for ($i = \count($block->opCodes) - 1; $i >= 0; --$i) {
            $scanOp = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                break;
            }
            if (OpCode::TYPE_FROM_CALLABLE === $scanOp->type) {
                return (int) $scanOp->arg1;
            }
            if (OpCode::TYPE_CLOSURE === $scanOp->type) {
                return (int) $scanOp->arg1;
            }
        }

        return null;
    }
}
