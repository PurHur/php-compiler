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
 * fdiv() — IEEE-754 float division (PHP 8.0+, ext/standard/math.c / zend_fdiv).
 *
 * Exactly two arguments — no rounding_mode overload in php-src (#23576; reverts #9918).
 */
final class fdiv extends Internal
{
    private const FUNCTION = 'fdiv';

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, self::FUNCTION, 2);
        // Z_PARAM_DOUBLE soft-null: E_DEPRECATED + coerce (php-src math.c; #29319, re-#24198).
        $a = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num1',
            $frame
        );
        $b = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'num2',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\fdiv($a, $b));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, self::FUNCTION, 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        [$left, $right] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            self::FUNCTION,
            'num1',
            'num2',
            'float',
            false
        );

        return $context->builder->fdiv($left, $right);
    }
}
