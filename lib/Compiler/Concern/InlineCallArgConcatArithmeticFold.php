<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Encapsed ConcatList / chained Concat / chained arithmetic call-arg fold helpers
 * (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgCompileTimeFold} so gen-0 split-TU can hollow
 * a smaller Concern TU. Mirrors php-src Zend/zend_compile.c SEND_* lowering for
 * hoisted concat/arithmetic producers (`zend_compile_expr` ConcatList /
 * BinaryOp paths) and unary-literal prelude binding (#13387 / #13466 / #15929).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgCompileTimeFold).
 */
trait InlineCallArgConcatArithmeticFold
{
    /**
     * Lower encapsed ConcatList call args when php-cfg allocates a dead arg temp (#13466).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveEncapsedConcatListCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        $concat = $this->concatListProducerForHoistedCallArg($cfgCallOp, $argIndex, $block, $arg);
        if (!$concat instanceof Op\Expr\ConcatList) {
            return null;
        }
        if (null === $block->slotForOperand($concat->result)) {
            $this->compileOp($concat, $block);
        }

        return $block->slotForOperand($concat->result);
    }

    /**
     * Lower chained BinaryOp\Concat call args when php-cfg allocates a dead arg temp (#13458, #13572).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveChainedConcatCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $chain = $this->chainedConcatInlineCallArgProducersBeforeCall(
            $block->orig->children,
            $callIndex,
            $cfgCallOp
        );
        if (null === $chain) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($cfgCallOp->args);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $last = $chain[\count($chain) - 1];
        if (null === $last->result) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($chain as $concat) {
                foreach ($this->compileExpr($concat, $block) as $op) {
                    $emitOps[] = $op;
                }
            }
        }

        return $block->slotForOperand($last->result);
    }

    /**
     * Lower chained Mul/Div/Plus/Minus call args when php-cfg allocates a dead arg temp (#15929).
     *
     * @param list<OpCode> $emitOps
     */
    private function tryResolveChainedArithmeticCallArgSlot(
        Operand $arg,
        Block $block,
        array &$emitOps,
        ?Op $cfgCallOp = null,
        int $argIndex = 0
    ): ?int {
        if (null === $cfgCallOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $chain = $this->chainedArithmeticInlineCallArgProducersBeforeCall(
            $block->orig->children,
            $callIndex,
            $cfgCallOp
        );
        if (null === $chain) {
            return null;
        }
        $soleHoisted = $this->soleNonEmbeddedCallArgIndex($cfgCallOp->args);
        if (null === $soleHoisted || $argIndex !== $soleHoisted) {
            return null;
        }
        $last = $chain[\count($chain) - 1];
        if (null === $last->result) {
            return null;
        }
        if (null === $block->slotForOperand($last->result)) {
            foreach ($chain as $arithmetic) {
                foreach ($this->compileExpr($arithmetic, $block) as $op) {
                    $emitOps[] = $op;
                }
            }
        }

        return $block->slotForOperand($last->result);
    }

    /**
     * php-cfg hoists encapsed ConcatList before FuncCall with a distinct dead arg temp (#13466).
     */
    private function concatListProducerForHoistedCallArg(
        ?Op $callOp,
        int $argIndex,
        Block $block,
        Operand $arg
    ): ?Op\Expr\ConcatList {
        if (null === $callOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $callArg = \is_array($callOp->args ?? null) ? ($callOp->args[$argIndex] ?? $arg) : $arg;
        // php-cfg links dead New_/call arg temps via $arg->ops even when ClassConstFetch sits
        // between ConcatList and the call (`new T("x$v", C::K)`, #22971 / #13466).
        $writer = $this->soleWriteExprForOperand($callArg);
        if ($writer instanceof Op\Expr\ConcatList) {
            return $writer;
        }
        foreach ($callArg->ops ?? [] as $op) {
            if ($op instanceof Op\Expr\ConcatList) {
                return $op;
            }
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\Assign) {
                if ($this->operandsReferToSameVariable($child->var, $callArg)) {
                    $assignExpr = $child->expr;
                    while ($assignExpr instanceof Operand\Temporary && null !== $assignExpr->original) {
                        $assignExpr = $assignExpr->original;
                    }
                    if ($assignExpr instanceof Op\Expr\ConcatList) {
                        return $assignExpr;
                    }
                    if ($i > 0) {
                        $prior = $block->orig->children[$i - 1];
                        if (
                            $prior instanceof Op\Expr\ConcatList
                            && null !== $prior->result
                            && (
                                $this->operandsReferToSameVariable($prior->result, $child->expr)
                                || $this->operandsReferToSameVariable($prior->result, $assignExpr)
                            )
                        ) {
                            return $prior;
                        }
                    }
                }

                break;
            }
            if ($child instanceof Op\Expr\ConcatList) {
                if (null !== $child->result) {
                    if ($this->operandsReferToSameVariable($child->result, $callArg)) {
                        return $child;
                    }
                    // php-cfg dead-temp alias: hoisted call arg temp may differ from ConcatList.result (#13466).
                    if ($i === $callIndex - 1 && $callArg instanceof Operand\Temporary) {
                        return $child;
                    }
                }

                return null;
            }
            // Sibling ClassConstFetch / ConstFetch / UnaryMinus between ConcatList and New_ (#22971).
            if (
                $child instanceof Op\Expr\ClassConstFetch
                || $child instanceof Op\Expr\ConstFetch
                || $child instanceof Op\Expr\UnaryMinus
                || $child instanceof Op\Expr\UnaryPlus
                || $child instanceof Op\Expr\PropertyFetch
                || $child instanceof Op\Expr\ArrayDimFetch
                || $child instanceof Op\Expr\BinaryOp\Concat
            ) {
                continue;
            }
            break;
        }

        return null;
    }

    /**
     * php-cfg echo ConcatList hoists sibling FuncCalls with Concat stmts between them (#13387).
     */
    private function unaryLiteralFeedsSiblingArrayDimFetchDim(?Op $callOp, Block $block): bool
    {
        if (null === $callOp || null === $block->orig) {
            return false;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex || $callIndex < 2) {
            return false;
        }
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if (!$child instanceof Op\Expr\UnaryMinus && !$child instanceof Op\Expr\UnaryPlus) {
                return false;
            }
            $next = $block->orig->children[$i + 1] ?? null;

            return $next instanceof Op\Expr\ArrayDimFetch
                && null !== $child->result
                && null !== $next->dim
                && (
                    $next->dim === $child->result
                    || $this->operandsReferToSameVariable($next->dim, $child->result)
                );
        }

        return false;
    }

    private function unaryLiteralProducerForHoistedCallArg(
        ?Op $callOp,
        int $argIndex,
        Block $block,
        Operand $arg
    ): Op\Expr\UnaryMinus|Op\Expr\UnaryPlus|null {
        if (null === $callOp || null === $block->orig) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $callOp, $block->orig);
        if (null === $callIndex) {
            return null;
        }
        $callArg = \is_array($callOp->args ?? null) ? ($callOp->args[$argIndex] ?? $arg) : $arg;
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $block->orig->children[$i];
            if ($child instanceof Op\Expr\UnaryMinus || $child instanceof Op\Expr\UnaryPlus) {
                if (null !== $child->result) {
                    if ($this->operandsReferToSameVariable($child->result, $callArg)) {
                        return $child;
                    }
                    // php-cfg dead-temp alias: hoisted call arg temp may differ from UnaryMinus.result (#13387, #13434).
                    // Trailing arg with immediate UnaryMinus/Plus (ceil(-2.5) after concat chain, ftruncate($f, -1)).
                    // ftruncate(fopen(), -1) arg #0 must not take callIndex-1 UnaryMinus — only trailing args (#12622).
                    if (
                        $i === $callIndex - 1
                        && $callArg instanceof Operand\Temporary
                        && is_array($callOp->args ?? null)
                        && $this->callArgIsDeadInlineTemporary($callOp->args[$argIndex] ?? $arg)
                    ) {
                        $argCount = \count($callOp->args);
                        if ($argIndex === $argCount - 1) {
                            return $child;
                        }
                        $producerSlot = $this->inlineHoistedProducerSlotIndexForCallArg(
                            $callOp->args,
                            $argIndex,
                            $block,
                            $callOp
                        );
                        if (null !== $producerSlot) {
                            // Single hoisted dead-temp unary arg (#13508): producer walk skips immediate
                            // UnaryMinus when the consumer FuncCall is the next sibling (same as trailing
                            // wiring for ceil(-2.5) / ftruncate($f, -1) in #13434).
                            if (0 === $producerSlot && 0 === $argIndex) {
                                $deadHoisted = 0;
                                foreach ($callOp->args as $hoistedArg) {
                                    if ($this->callArgIsDeadInlineTemporary($hoistedArg)) {
                                        ++$deadHoisted;
                                    }
                                }
                                if (1 === $deadHoisted) {
                                    return $child;
                                }
                            }
                            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                                $block->orig->children,
                                $callOp
                            );
                            if (($producers[$producerSlot] ?? null) === $child) {
                                return $child;
                            }
                        }
                    }
                }

                return null;
            }
            if ($child instanceof Op\Expr\BinaryOp\Concat) {
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch) {
                continue;
            }
            if ($child instanceof Op\Expr\FuncCall || $child instanceof Op\Expr\NsFuncCall) {
                break;
            }
        }

        return null;
    }
}
