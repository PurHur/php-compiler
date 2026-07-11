<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathRound;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitRoundModeArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * fdiv() — IEEE-754 float division (PHP 8.0, ext/standard/math.c / zend_fdiv).
 *
 * PHP 8.4+: optional rounding_mode (RoundingMode|int) rounds quotient to integer (#9918).
 */
final class fdiv extends Internal
{
    private const FUNCTION = 'fdiv';

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 3 : 2;
        if ($argc < 2 || $argc > $maxArgs) {
            throw new \LogicException(
                3 === $maxArgs
                    ? 'fdiv() requires two or three arguments'
                    : 'fdiv() requires exactly two arguments'
            );
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
        $quotient = \fdiv($a, $b);
        if ($argc < 3) {
            $frame->returnVar->float($quotient);

            return;
        }
        $mode = VmRoundMode::resolveRoundModeArg(
            $frame->calledArgs[2]->resolveIndirect(),
            self::FUNCTION,
            'rounding_mode'
        );
        $numVar = new Variable();
        $numVar->float($quotient);
        VmRound::apply($frame->returnVar, $numVar, 0, $mode);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 3 : 2;
        if ($argc < 2 || $argc > $maxArgs) {
            throw new \LogicException(
                3 === $maxArgs
                    ? 'fdiv() requires two or three arguments'
                    : 'fdiv() requires exactly two arguments'
            );
        }
        [$left, $right] = JitFdiv::lowerOperands($context, $args[0], $args[1]);
        $quotient = $context->builder->fdiv($left, $right);
        if ($argc < 3) {
            return $quotient;
        }
        $mode = JitRoundModeArg::lower($context, $args[2], self::FUNCTION, 'rounding_mode');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return MathRound::invoke($context, $quotient, $zero, $mode);
    }
}
