<?php

declare(strict_types=1);

namespace PHPCompiler\Visitor;

use PHPCfg\AbstractVisitor;
use PHPCfg\Block;
use PHPCfg\Op\Expr\Exit_;
use PHPCfg\Op\Expr\FuncCall;
use PHPCompiler\Ast\ExitTwoArgDesugar;

/**
 * Lower desugared `__phpcExitTwo($status, $message)` marker calls to CFG Exit_ (#6718).
 */
final class ExitTwoArgResolver extends AbstractVisitor
{
    public function enterBlock(Block $block, Block $prior = null): void
    {
        foreach ($block->children as $i => $op) {
            if (!$op instanceof FuncCall || !$this->isMarkerCall($op)) {
                continue;
            }
            $block->children[$i] = $this->toExitOp($op);
        }
    }

    private function isMarkerCall(FuncCall $op): bool
    {
        if (!$op->name instanceof \PHPCfg\Operand\Literal || !is_string($op->name->value)) {
            return false;
        }

        return ExitTwoArgDesugar::MARKER === $op->name->value;
    }

    private function toExitOp(FuncCall $op): Exit_
    {
        if (2 !== count($op->args)) {
            throw new \LogicException(ExitTwoArgDesugar::MARKER.'() expects exactly two arguments');
        }

        $exit = Exit_::withMessage($op->args[0], $op->args[1], $op->getAttributes());
        $exit->result->replaceWith($op->result);

        return $exit;
    }
}
