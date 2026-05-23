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
 * hypot() with two integer or float arguments (subset of PHP standard library).
 */
final class hypot extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('hypot() requires exactly two arguments');
        }
        $x = $frame->calledArgs[0]->resolveIndirect();
        $y = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\hypot(self::toFloat($x), self::toFloat($y)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('hypot() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $left = pow::toJitDouble($context, $args[0], $double);
        $right = pow::toJitDouble($context, $args[1], $double);
        $fn = $context->lookupFunction('hypot');

        return $context->builder->call($fn, $left, $right);
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('hypot() only supports integers and floats in this compiler build');
    }
}
