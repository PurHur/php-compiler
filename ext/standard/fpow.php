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
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fpow() — IEEE-754 floating power (PHP 8.4, ext/standard/math.c / zend_fpow).
 *
 * php-src arity is exactly 2 — no rounding_mode (#23577; re-#9990 phantom).
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
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 2 arguments, %d given', self::FUNCTION, $argc)
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
        $frame->returnVar->float(VmMath::fpow($num, $exponent));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 2 arguments, %d given', self::FUNCTION, $argc)
            );
        }
        [$base, $exp] = JitFdiv::lowerOperands($context, $args[0], $args[1], self::FUNCTION, 'num', 'exponent', 'float', true);

        return MathFpow::invoke($context, $base, $exp);
    }
}
