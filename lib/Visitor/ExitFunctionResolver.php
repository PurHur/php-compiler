<?php

declare(strict_types=1);

namespace PHPCompiler\Visitor;

use PHPCfg\AbstractVisitor;
use PHPCfg\Block;
use PHPCfg\Op\Expr\FirstClassCallable;
use PHPCfg\Op\Expr\FuncCall;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCompiler\Ast\ExitFunctionDesugar;

/**
 * Lower desugared exit/die marker calls to exit()/die() builtin FuncCalls (#6975).
 */
final class ExitFunctionResolver extends AbstractVisitor
{
    public function enterBlock(Block $block, ?Block $prior = null): void
    {
        foreach ($block->children as $op) {
            if ($op instanceof FuncCall && $this->isMarkerName($op->name)) {
                $this->renameToBuiltin($op->name);
                continue;
            }
            if ($op instanceof FirstClassCallable
                && FirstClassCallable::KIND_FUNCTION === $op->kind
                && $this->isMarkerName($op->name)
            ) {
                $this->renameToBuiltin($op->name);
            }
        }
    }

    private function isMarkerName(?Operand $name): bool
    {
        if (!$name instanceof Literal || !is_string($name->value)) {
            return false;
        }

        return \in_array($name->value, [ExitFunctionDesugar::MARKER_EXIT, ExitFunctionDesugar::MARKER_DIE], true);
    }

    private function renameToBuiltin(Literal $name): void
    {
        $name->value = ExitFunctionDesugar::MARKER_DIE === $name->value ? 'die' : 'exit';
    }
}
