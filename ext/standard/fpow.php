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
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Builtin\MathRound;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitRoundModeArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * fpow() — IEEE-754 floating power (PHP 8.4, ext/standard/math.c / zend_fpow).
 *
 * PHP 8.4+: optional rounding_mode (RoundingMode|int) rounds result to integer (#9990).
 */
final class fpow extends Internal
{
    private const FUNCTION = 'fpow';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 3 : 2;
        if ($argc < 2 || $argc > $maxArgs) {
            throw new \LogicException(
                3 === $maxArgs
                    ? self::FUNCTION.'() requires two or three arguments'
                    : self::FUNCTION.'() requires exactly two arguments'
            );
        }
        $num = VmMath::parseForwardProfileStrictDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num',
            $frame
        );
        $exponent = VmMath::parseForwardProfileStrictDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'exponent',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $power = VmMath::fpow($num, $exponent);
        if ($argc < 3) {
            $frame->returnVar->float($power);

            return;
        }
        $mode = VmRoundMode::resolveRoundModeArg(
            $frame->calledArgs[2]->resolveIndirect(),
            self::FUNCTION,
            'rounding_mode'
        );
        $numVar = new Variable();
        $numVar->float($power);
        VmRound::apply($frame->returnVar, $numVar, 0, $mode);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 3 : 2;
        if ($argc < 2 || $argc > $maxArgs) {
            throw new \LogicException(
                3 === $maxArgs
                    ? self::FUNCTION.'() requires two or three arguments'
                    : self::FUNCTION.'() requires exactly two arguments'
            );
        }
        [$base, $exp] = JitFdiv::lowerOperands($context, $args[0], $args[1], self::FUNCTION, 'num', 'exponent', 'float', true);
        $power = MathFpow::invoke($context, $base, $exp);
        if ($argc < 3) {
            return $power;
        }
        $mode = JitRoundModeArg::lower($context, $args[2], self::FUNCTION, 'rounding_mode');
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return MathRound::invoke($context, $power, $zero, $mode);
    }
}
