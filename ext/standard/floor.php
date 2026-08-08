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
use PHPCompiler\JIT\Builtin\MathFloor;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * floor() for integer or float arguments (subset of PHP standard library).
 */
final class floor extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#28476).
        $this->requireExactArgCount($frame, 'floor', 1);
        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'floor',
            1,
            'num',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::floor((float) $num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT/JIT) — #28476.
        if (!$this->requireExactJitArgCount($context, $args, 'floor', 1)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        $asFloat = JitMathNumberArg::lowerToDouble($context, $args[0], 'floor', 1, 'num');

        return MathFloor::invoke($context, $asFloat);
    }
}
