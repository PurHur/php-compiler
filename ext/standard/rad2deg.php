<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * rad2deg() for integer or float arguments (subset of PHP standard library).
 */
final class rad2deg extends Internal
{
    private const FACTOR = 180.0 / \M_PI;

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('rad2deg() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(self::FACTOR * self::toFloat($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('rad2deg() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $asFloat = pow::toJitDouble($context, $args[0], $double);
        $factor = $double->constReal(self::FACTOR);

        return $context->builder->fMul($asFloat, $factor);
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('rad2deg() only supports integers and floats in this compiler build');
    }
}
