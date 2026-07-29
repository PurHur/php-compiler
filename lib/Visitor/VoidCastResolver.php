<?php

declare(strict_types=1);

namespace PHPCompiler\Visitor;

use PHPCfg\AbstractVisitor;
use PHPCfg\Block;
use PHPCfg\Op\Expr\Cast\Void_;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Operand;
use PHPCompiler\Ast\VoidCastDesugar;

/**
 * Lower desugared `__phpcVoidCast($expr)` marker calls to CFG Void_ cast (#7346).
 */
final class VoidCastResolver extends AbstractVisitor
{
    public function enterBlock(Block $block, ?Block $prior = null): void
    {
        foreach ($block->children as $i => $op) {
            if (!$op instanceof FuncCall || !$this->isMarkerCall($op)) {
                continue;
            }
            $block->children[$i] = $this->toVoidCast($op);
        }
    }

    private function isMarkerCall(FuncCall $op): bool
    {
        if (!$op->name instanceof Operand\Literal || !\is_string($op->name->value)) {
            return false;
        }

        return VoidCastDesugar::MARKER === $op->name->value;
    }

    private function toVoidCast(FuncCall $op): Void_
    {
        if (1 !== \count($op->args)) {
            throw new \LogicException(VoidCastDesugar::MARKER.'() expects exactly one argument');
        }

        $cast = new Void_($op->args[0], $op->getAttributes());
        $cast->result->replaceWith($op->result);

        return $cast;
    }
}
