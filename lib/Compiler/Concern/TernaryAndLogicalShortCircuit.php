<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use SplObjectStorage;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPTypes\Type;

/**
 * Ternary (`?:`) jump/merge detect and nullable-return rewrite helpers (#36387 / #36403).
 *
 * Companion to {@see TernaryMergeAndLogicalShortCircuitSlots} (merge-phi
 * detect / logical short-circuit slots) and {@see TernaryMergeVarSlotCompile}
 * (merge var-slot wiring). Jump-target detect plus nullable `!== null`
 * rewrite stay here so gen-0 split-TU can hollow a smaller Concern TU.
 * Mirrors php-src Zend/zend_compile.c ternary compile — move-only; no
 * behavior change intended.
 */

trait TernaryAndLogicalShortCircuit
{
    /**
     * @return list<CfgBlock>
     */
    private function ternaryMergeTargets(CfgBlock $branchCfg): array
    {
        $merges = [];
        foreach ($branchCfg->children as $child) {
            if (!$child instanceof Op\Stmt\Jump) {
                continue;
            }
            $merge = $child->target;
            if (\count($merge->parents) >= 2) {
                $merges[] = $merge;
            }
        }

        return $merges;
    }

    /** CFG block jumped to at the end of a ?: / if branch (may have one parent while lowering). */
    private function branchJumpMergeTarget(CfgBlock $branchCfg): ?CfgBlock
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Stmt\Jump) {
                return $child->target;
            }
        }

        return null;
    }

    /** Foreach loop heads use Iterator_Valid — not ?: merge blocks (#5657). */
    private function isForeachIteratorHeaderCfgBlock(CfgBlock $cfg): bool
    {
        foreach ($cfg->children as $child) {
            if ($child instanceof Op\Iterator\Valid) {
                return true;
            }
        }

        return false;
    }

    /** Both ?: arms jump to the same CFG merge block (echo/assign phi, #3790, #5510). */
    private function jumpIfTargetsTernaryMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (\count($ifMerge->parents) < 2) {
            return false;
        }

        return $this->mergeCfgBlockUsesTernaryPhi($ifMerge);
    }

    /**
     * `||` short-circuit: php-cfg puts literal `true` on JumpIf->if and (bool) cast on ->else.
     * Lower else before if so the cast arm records the phi slot for the literal arm (#12745).
     */
    private function jumpIfTargetsLogicalOrShortCircuitLiteralIf(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (!$this->mergeCfgBlockUsesLogicalShortCircuit($ifMerge)) {
            return false;
        }
        $ifTail = $this->branchTailExprBeforeJump($stmt->if);
        $elseTail = $this->branchTailExprBeforeJump($stmt->else);

        return $ifTail instanceof Op\Expr\Assign
            && $ifTail->expr instanceof Operand\Literal
            && $elseTail instanceof Op\Expr\Cast\Bool_;
    }

    /**
     * `&&` short-circuit: php-cfg puts (bool) cast on JumpIf->if and literal `false` on ->else.
     * Lower if before else so the cast arm records the phi slot (#24506) — the opposite of `||`.
     */
    private function jumpIfTargetsLogicalAndShortCircuitCastIf(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        if (!$this->mergeCfgBlockUsesLogicalShortCircuit($ifMerge)) {
            return false;
        }
        $ifTail = $this->branchTailExprBeforeJump($stmt->if);
        $elseTail = $this->branchTailExprBeforeJump($stmt->else);

        return $ifTail instanceof Op\Expr\Cast\Bool_
            && $elseTail instanceof Op\Expr\Assign
            && $elseTail->expr instanceof Operand\Literal;
    }

    /** Both ?: arms jump to a merge block ending in RETURN (#4280, #8563). */
    private function jumpIfTargetsReturnMerge(Op\Stmt\JumpIf $stmt): bool
    {
        $ifMerge = $this->branchJumpMergeTarget($stmt->if);
        $elseMerge = $this->branchJumpMergeTarget($stmt->else);
        if (null === $ifMerge || $ifMerge !== $elseMerge) {
            return false;
        }
        foreach ($ifMerge->children as $child) {
            if ($child instanceof Op\Terminal\Return_) {
                return true;
            }
        }

        return false;
    }

    /**
     * `null !== $param ? $param : null` mis-lowers in AOT when the param arm is if-entry (#8563).
     * Rewrite to `null === $param ? null : $param` at compile time (php-src equivalent).
     */
    private function shouldRewriteNullableNeNullReturnTernary(
        Op\Stmt\JumpIf $stmt,
        ?Op\Expr\BinaryOp\NotIdentical $ne = null
    ): bool {
        if (!$this->jumpIfTargetsReturnMerge($stmt)) {
            return false;
        }
        if (null !== $ne) {
            return $this->branchCfgAssignsNullConst($stmt->else)
                && $this->branchCfgAssignsNonNullValue($stmt->if)
                && ($this->operandIsNull($ne->left) || $this->operandIsNull($ne->right));
        }
        $ne = $this->unwrapOperandToNotIdentical($stmt->cond);
        if (null === $ne) {
            return false;
        }

        return $this->branchCfgAssignsNullConst($stmt->else)
            && $this->branchCfgAssignsNonNullValue($stmt->if)
            && ($this->operandIsNull($ne->left) || $this->operandIsNull($ne->right));
    }

    private function operandIsNull(Operand $operand): bool
    {
        if ($operand->type instanceof Type && Type::TYPE_NULL === $operand->type->type) {
            return true;
        }

        return $this->exprIsNullConst($operand);
    }

    private function unwrapOperandToNotIdentical(Operand $operand): ?Op\Expr\BinaryOp\NotIdentical
    {
        while ($operand instanceof Operand\Temporary) {
            if ($operand->original instanceof Op\Expr\BinaryOp\NotIdentical) {
                return $operand->original;
            }
            if (null === $operand->original) {
                return null;
            }
            $operand = $operand->original;
        }

        return $operand instanceof Op\Expr\BinaryOp\NotIdentical ? $operand : null;
    }

    private function branchCfgAssignsNullConst(CfgBlock $branchCfg): bool
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && null !== $child->expr && $this->operandIsNull($child->expr)) {
                return true;
            }
        }

        return false;
    }

    private function exprIsNullConst(Operand $expr): bool
    {
        while ($expr instanceof Operand\Temporary && null !== $expr->original) {
            if ($expr->original instanceof Op\Expr\ConstFetch) {
                return $this->constFetchIsNull($expr->original);
            }
            $expr = $expr->original;
        }

        return $expr instanceof Op\Expr\ConstFetch && $this->constFetchIsNull($expr);
    }

    /** Explicit null literal in a call arg list — not a hoisted inline closure temp (#14893). */
    private function callArgIsNullLiteral(
        ?Operand $arg,
        ?Op $cfgCallOp = null,
        ?int $argIndex = null,
        ?Block $block = null
    ): bool {
        if (null === $arg) {
            return false;
        }
        if ($arg instanceof Operand\NullOperand) {
            return true;
        }
        if ($this->exprIsNullConst($arg)) {
            return true;
        }
        if ($arg instanceof Op\Expr\ConstFetch && $this->constFetchIsNull($arg)) {
            return true;
        }
        if (
            null !== $cfgCallOp
            && null !== $block
            && null !== $argIndex
            && $this->callArgIsDeadInlineTemporary($arg)
        ) {
            $prelude = $this->hoistedDeadInlinePreludeProducerForCallArgIndex($cfgCallOp, $argIndex, $block);
            if ($prelude instanceof Op\Expr\ConstFetch && $this->constFetchIsNull($prelude)) {
                return true;
            }
            // `new C(null, [...])` — Array_ sits between ConstFetch null and New_, so the
            // immediate-prelude walker misses null; use ordinal producer match (#22770).
            if (null !== $block->orig && property_exists($cfgCallOp, 'args') && \is_array($cfgCallOp->args)) {
                $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
                    $block->orig->children,
                    $cfgCallOp
                );
                if ([] !== $producers) {
                    $matched = $this->matchInlineCallArgProducer(
                        $producers,
                        $cfgCallOp->args,
                        $argIndex,
                        $cfgCallOp,
                        $block
                    );
                    if ($matched instanceof Op\Expr\ConstFetch && $this->constFetchIsNull($matched)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function constFetchIsNull(Op\Expr\ConstFetch $fetch): bool
    {
        $name = $fetch->name;
        while ($name instanceof Operand\Temporary && null !== $name->original) {
            $name = $name->original;
        }

        return $name instanceof Operand\Literal
            && 'null' === strtolower((string) $name->value);
    }

    /**
     * Zend IS_CONST array-unpack of non-array (null/true/false/int/float/string) is
     * compile-time E_ERROR; named consts and variables are runtime catchable (#27952).
     */
    private function isCompileTimeNonTraversableArrayUnpackOperand(Operand $operand): bool
    {
        if ($operand instanceof Operand\Literal) {
            return !is_array($operand->value);
        }
        foreach ($operand->ops as $op) {
            if (!$op instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $op->name;
            while ($name instanceof Operand\Temporary && null !== $name->original) {
                $name = $name->original;
            }
            if (!$name instanceof Operand\Literal || !is_string($name->value)) {
                continue;
            }
            $lc = strtolower($name->value);
            if ('null' === $lc || 'true' === $lc || 'false' === $lc) {
                return true;
            }
        }

        return false;
    }

    /**
     * ADD_ARRAY_UNPACK compile-time Fatal message — PHP 8.4+ appends {@code , <type> given} (#30055).
     */
    private function arrayUnpackNonTraversableCompileMessage(Operand $operand): string
    {
        if ($operand instanceof Operand\Literal) {
            return VM\ArraySpread::nonTraversableMessageForPhpValue($operand->value);
        }
        foreach ($operand->ops as $op) {
            if (!$op instanceof Op\Expr\ConstFetch) {
                continue;
            }
            $name = $op->name;
            while ($name instanceof Operand\Temporary && null !== $name->original) {
                $name = $name->original;
            }
            if (!$name instanceof Operand\Literal || !is_string($name->value)) {
                continue;
            }
            $lc = strtolower($name->value);
            if ('null' === $lc) {
                return VM\ArraySpread::nonTraversableMessageForPhpValue(null);
            }
            if ('true' === $lc) {
                return VM\ArraySpread::nonTraversableMessageForPhpValue(true);
            }
            if ('false' === $lc) {
                return VM\ArraySpread::nonTraversableMessageForPhpValue(false);
            }
        }

        return VM\ArraySpread::NON_TRAVERSABLE_MESSAGE;
    }

    private function branchCfgAssignsNonNullValue(CfgBlock $branchCfg): bool
    {
        foreach ($branchCfg->children as $child) {
            if ($child instanceof Op\Expr\Assign && !$this->exprIsNullConst($child->expr)) {
                return true;
            }
        }

        return false;
    }

    private function funcReturnTypeIsNullableScalar(Block $block): bool
    {
        if (null === $block->func) {
            return false;
        }
        $returnType = $block->func->returnType;

        return $returnType instanceof Op\Type\Nullable;
    }

    private function operandIsImplicitNullableParam(Operand $operand, Block $block): bool
    {
        if (!$operand instanceof CfgVariable) {
            return false;
        }
        if (null === $block->func) {
            return false;
        }
        $varName = $operand->name;
        while ($varName instanceof Temporary && null !== $varName->original) {
            $varName = $varName->original;
        }
        if (!$varName instanceof Literal || !is_string($varName->value)) {
            return false;
        }
        foreach ($block->func->params as $param) {
            $paramName = $param->name;
            while ($paramName instanceof Temporary && null !== $paramName->original) {
                $paramName = $paramName->original;
            }
            if (!$paramName instanceof Literal || $paramName->value !== $varName->value) {
                continue;
            }
            if ($param->declaredType instanceof Op\Type\Nullable) {
                return true;
            }
            $slot = $block->slotForOperand($param->result);

            return null !== $slot && isset($block->paramImplicitNullable[$slot]);
        }

        return false;
    }

    /** `return (null ?… $param : null)` / `return (null !== $param ? $param : null)` → `$param ?? null` (#8563). */
    private function nullableParamFromReturnTernaryArms(Op\Stmt\JumpIf $stmt, Block $block): ?Operand
    {
        if (!$this->jumpIfTargetsReturnMerge($stmt) || !$this->funcReturnTypeIsNullableScalar($block)) {
            return null;
        }
        $ifNull = $this->branchCfgAssignsNullConst($stmt->if);
        $elseNull = $this->branchCfgAssignsNullConst($stmt->else);
        if ($ifNull === $elseNull) {
            return null;
        }
        $valueBranch = $ifNull ? $stmt->else : $stmt->if;
        foreach ($valueBranch->children as $child) {
            if (!$child instanceof Op\Expr\Assign || null === $child->expr || $this->exprIsNullConst($child->expr)) {
                continue;
            }
            $src = $child->expr;
            while ($src instanceof Temporary && null !== $src->original) {
                $src = $src->original;
            }
            if ($src instanceof CfgVariable && $this->operandIsImplicitNullableParam($src, $block)) {
                return $src;
            }
        }

        return null;
    }

    private function syntheticNullConstOperand(): Operand
    {
        $nullLit = new Literal(null);
        $nullLit->type = Type::null();

        return $nullLit;
    }

    /** AOT-safe lowering: implicit nullable param returns via ?? null (proven native ABI path). */
    private function emitImplicitNullableParamCoalesceReturn(Operand $paramOp, Block $block): void
    {
        $coalesce = new Op\Expr\BinaryOp\Coalesce($paramOp, $this->syntheticNullConstOperand());
        $endBlock = $this->compileCoalesce($coalesce, $block);
        $endBlock->addOpCode(new OpCode(
            OpCode::TYPE_RETURN,
            $this->compileOperand($coalesce->result, $endBlock, true)
        ));
    }

}
