<?php

declare(strict_types=1);

namespace PHPCompiler\Visitor;

use PHPCfg\AbstractVisitor;
use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Op\Expr\In_;
use PHPCfg\Operand;

/**
 * Lower desugared `__phpcLangIn($needle, $haystack)` marker calls to CFG In_ (#4682).
 */
final class InOperatorResolver extends AbstractVisitor
{
    private const MARKER = '__phpcLangIn';

    public function enterBlock(Block $block, ?Block $prior = null): void
    {
        foreach ($block->children as $i => $op) {
            if (!$op instanceof FuncCall || !$this->isMarkerCall($op)) {
                continue;
            }
            $block->children[$i] = $this->toInOp($op);
        }
    }

    private function isMarkerCall(FuncCall $op): bool
    {
        if (!$op->name instanceof Operand\Literal || !is_string($op->name->value)) {
            return false;
        }

        return self::MARKER === $op->name->value;
    }

    private function toInOp(FuncCall $op): In_
    {
        if (2 !== count($op->args)) {
            throw new \LogicException(self::MARKER.'() expects exactly two arguments');
        }

        $in = new In_($op->args[0], $op->args[1], $op->getAttributes());
        $in->result->replaceWith($op->result);

        return $in;
    }
}
