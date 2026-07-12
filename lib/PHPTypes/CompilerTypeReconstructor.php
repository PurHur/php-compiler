<?php

declare(strict_types=1);

namespace PHPCompiler\PHPTypes;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPTypes\TypeReconstructor;
use SplObjectStorage;

/**
 * php-compiler TypeReconstructor — guards dynamic Class::{$name} const fetch (#18093, re-#17801).
 *
 * PHP 8.3+ dynamic class constant fetch uses a runtime Temporary for the const name;
 * vendor TypeReconstructor must not read {@see Operand\Literal::$value} on non-Literals.
 *
 * @see \PHPTypes\TypeReconstructor::resolveOp_Expr_ClassConstFetch
 * @see \PHPTypes\TypeReconstructor::resolveClassConstant
 */
final class CompilerTypeReconstructor extends TypeReconstructor
{
    protected function resolveOp_Expr_ClassConstFetch(Operand $var, Op\Expr\ClassConstFetch $op, SplObjectStorage $resolved)
    {
        if (! ($op->name instanceof Operand\Literal)) {
            return false;
        }

        return parent::resolveOp_Expr_ClassConstFetch($var, $op, $resolved);
    }

    protected function resolveClassConstant($class, $op, SplObjectStorage $resolved)
    {
        if (! ($op->name instanceof Operand\Literal)) {
            return false;
        }

        return parent::resolveClassConstant($class, $op, $resolved);
    }
}
