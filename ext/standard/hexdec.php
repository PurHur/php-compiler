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
 * hexdec() for string arguments (subset of PHP standard library).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(hexdec)
 */
final class hexdec extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('hexdec() requires exactly one argument');
        }
        $hexString = VmString::stringBuiltinArgForFrame($frame, 0, 'hexdec', 0, 'hex_string');
        VmMath::assignRadixToReturn($frame->returnVar, $hexString, 16);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('hexdec() requires exactly one argument');
        }

        return MathBaseConvert::baseToZvalCall(
            $context,
            $this->stringDataPtr(
                $context,
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hexdec', 0, 'hex_string')
            ),
            16
        );
    }
}
