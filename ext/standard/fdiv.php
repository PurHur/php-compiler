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
use PHPLLVM\Value;

/**
 * fdiv() — IEEE-754 float division (PHP 8.0, ext/standard/math.c / zend_fdiv).
 */
final class fdiv extends Internal
{
    private const FUNCTION = 'fdiv';

    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('fdiv() requires exactly two arguments');
        }
        $a = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num1'
        );
        $b = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'num2'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\fdiv($a, $b));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== count($args)) {
            throw new \LogicException('fdiv() requires exactly two arguments');
        }
        [$left, $right] = JitFdiv::lowerOperands($context, $args[0], $args[1]);

        return $context->builder->fdiv($left, $right);
    }
}
