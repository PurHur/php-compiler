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
use PHPCompiler\JIT\Builtin\MathAtan2;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * atan2() for two integer or float arguments (subset of PHP standard library).
 */
final class atan2 extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#21982).
        $this->requireExactArgCount($frame, 'atan2', 2);
        // Z_PARAM_DOUBLE soft-null: E_DEPRECATED + coerce (php-src math.c; #29319, re-#24198).
        $y = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'atan2',
            1,
            'y',
            $frame
        );
        $x = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'atan2',
            2,
            'x',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::atan2($y, $x));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'atan2', 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        [$y, $x] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            'atan2',
            'y',
            'x',
            'float',
            false
        );

        return MathAtan2::invoke($context, $y, $x);
    }


}
