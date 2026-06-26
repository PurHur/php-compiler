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
use PHPCompiler\JIT\Builtin\MathBaseConvert;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * octdec() for string arguments (subset of PHP standard library).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(octdec)
 */
final class octdec extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('octdec() requires exactly one argument');
        }
        $octalString = VmString::stringBuiltinArgForFrame($frame, 0, 'octdec', 0, 'octal_string');
        VmMath::assignRadixToReturn($frame->returnVar, $octalString, 8);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('octdec() requires exactly one argument');
        }

        return MathBaseConvert::baseToZvalCall(
            $context,
            $this->stringDataPtr(
                $context,
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'octdec', 0, 'octal_string')
            ),
            8
        );
    }
}
