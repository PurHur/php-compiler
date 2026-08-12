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
use PHPCompiler\JIT\Builtin\MathExp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * exp() for integer or float arguments (subset of PHP standard library).
 */
final class exp extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#30534).
        $this->requireExactArgCount($frame, 'exp', 1);
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'exp',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::exp($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT/JIT) — #30534.
        if (!$this->requireExactJitArgCount($context, $args, 'exp', 1)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        // Z_PARAM_DOUBLE via JitFdiv — strict_types null/string TypeError (#29782).
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'exp', 'float');

        return MathExp::invoke($context, $asFloat);
    }

}
