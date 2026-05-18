<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as VarOperand;
use PHPCompiler\VM\Variable;

/**
 * Resolve a hoisted operand back to a source variable name when possible.
 */
final class OperandName
{
    public static function resolve(Operand $op): ?string
    {
        while ($op instanceof Temporary) {
            if (null === $op->original) {
                return null;
            }
            $op = $op->original;
        }
        if (!$op instanceof VarOperand) {
            return null;
        }
        $nameOp = $op->name;
        if (!$nameOp instanceof Literal) {
            return null;
        }
        if (null !== $nameOp->type && Variable::mapFromType($nameOp->type) !== Variable::TYPE_STRING) {
            return null;
        }
        if (!is_string($nameOp->value)) {
            return null;
        }

        return $nameOp->value;
    }
}
