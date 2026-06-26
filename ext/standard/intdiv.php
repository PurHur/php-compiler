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
use PHPCompiler\JIT\JitNumericDivisionGuard;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intdiv() — integer division (php-src ext/standard/math.c; #4982 numeric-string, #5360 float truncation).
 */
final class intdiv extends Internal
{
    private const FUNCTION = 'intdiv';

    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('intdiv() requires exactly two arguments');
        }
        $num1 = VmMath::parseIntBuiltinArgForFrame(
            $frame,
            0,
            self::FUNCTION,
            1,
            'num1'
        );
        $num2 = VmMath::parseIntBuiltinArgForFrame(
            $frame,
            1,
            self::FUNCTION,
            2,
            'num2'
        );
        if (0 === $num2) {
            throw new \DivisionByZeroError('Division by zero');
        }
        if (\PHP_INT_MIN === $num1 && -1 === $num2) {
            throw new \ArithmeticError('Division of PHP_INT_MIN by -1 is not an integer');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(\intdiv($num1, $num2));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('intdiv() requires exactly two arguments');
        }
        [$left, $right] = JitIntdiv::lowerOperands($context, $args[0], $args[1]);
        JitNumericDivisionGuard::emitZeroLongDivisorGuard($context, $right, 'Division by zero');
        JitNumericDivisionGuard::emitIntMinNegOneOverflowGuard(
            $context,
            $left,
            $right,
            'Division of PHP_INT_MIN by -1 is not an integer'
        );

        return $context->builder->signedDiv($left, $right);
    }
}
