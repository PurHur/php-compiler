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
use PHPCompiler\JIT\Builtin\MathSin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * sin() for integer or float arguments (subset of PHP standard library).
 */
final class sin extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#30534).
        $this->requireExactArgCount($frame, 'sin', 1);
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            'sin',
            1,
            'num'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::sin($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT/JIT) — #30534.
        if (!$this->requireExactJitArgCount($context, $args, 'sin', 1)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'sin', 'float');

        return MathSin::invoke($context, $asFloat);
    }

}
