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
 * intdiv() — integer division (php-src ext/standard/math.c; #4982 numeric-string parity).
 */
final class intdiv extends Internal
{
    private const FUNCTION = 'intdiv';

    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('intdiv() requires exactly two arguments');
        }
        $num1 = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num1'
        );
        $num2 = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'num2'
        );
        if (0 === $num2) {
            throw new \DivisionByZeroError('intdiv() division by zero');
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

        return $context->builder->signedDiv($left, $right);
    }
}
