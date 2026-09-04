<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;

/**
 * Reject invalid write-context operands at compile time (#36403).
 */
trait WriteContextRejects
{
    protected function isCallReturnWriteExpr(Op $op): bool
    {
        return $op instanceof Op\Expr\MethodCall
            || $op instanceof Op\Expr\StaticCall
            || $op instanceof Op\Expr\NullsafeMethodCall
            || $op instanceof Op\Expr\FuncCall
            || $op instanceof Op\Expr\NsFuncCall;
    }

    /**
     * Direct call-result lvalue only — dim/prop of a call return remain writable (#26436).
     */
    protected function findDirectCallReturnForWriteOperand(?Operand $operand, ?Block $block): ?Op\Expr
    {
        if (null === $operand || null === $block || null === $block->orig) {
            return null;
        }
        if (
            $operand instanceof Operand\Temporary
            && null !== $operand->original
            && $operand->original instanceof Op
            && $this->isCallReturnWriteExpr($operand->original)
        ) {
            return $operand->original;
        }
        foreach ($block->orig->children as $child) {
            if (!$child instanceof Op\Expr || !$this->isCallReturnWriteExpr($child)) {
                continue;
            }
            if ($child->result === $operand) {
                return $child;
            }
            if ($this->operandsReferToSameVariable($child->result, $operand)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Zend zend_compile.c: method/function call results are illegal write targets (#26436).
     *
     * @return never
     */
    protected function rejectCallReturnInWriteContext(?Operand $var, ?Block $block = null, ?Op $siteOp = null): void
    {
        $call = $this->findDirectCallReturnForWriteOperand($var, $block);
        if (null === $call) {
            return;
        }
        $site = $siteOp ?? $call;
        if (
            $call instanceof Op\Expr\FuncCall
            || $call instanceof Op\Expr\NsFuncCall
        ) {
            $this->throwWriteContextCompileFatal(
                "Can't use function return value in write context",
                $var,
                $block,
                $site,
            );
        }
        $this->throwWriteContextCompileFatal(
            "Can't use method return value in write context",
            $var,
            $block,
            $site,
        );
    }
}
