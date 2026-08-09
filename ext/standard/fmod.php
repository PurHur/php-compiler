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
use PHPCompiler\JIT\Builtin\MathFmod;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fmod() with two integer or float arguments (subset of PHP standard library).
 */
final class fmod extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#21982).
        $this->requireExactArgCount($frame, 'fmod', 2);
        // Z_PARAM_DOUBLE soft-null: E_DEPRECATED + coerce (php-src math.c; #29319, re-#24198).
        $num1 = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'fmod',
            1,
            'num1',
            $frame
        );
        $num2 = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'fmod',
            2,
            'num2',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::fmod($num1, $num2));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'fmod', 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        [$left, $right] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'fmod',
            'num1',
            'num2',
            'float',
            false
        );

        return MathFmod::invoke($context, $left, $right);
    }

}
