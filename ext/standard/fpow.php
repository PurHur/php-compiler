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
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow)
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
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(self::FUNCTION.'() requires exactly two arguments');
        }
        $num = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num'
        );
        $exponent = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'exponent'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::fpow($num, $exponent));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(self::FUNCTION.'() requires exactly two arguments');
        }
        [$base, $exp] = JitFdiv::lowerOperands($context, $args[0], $args[1], self::FUNCTION, 'num', 'exponent');

        return MathFpow::invoke($context, $base, $exp);
    }
}
