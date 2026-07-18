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
 * bindec() for string arguments (subset of PHP standard library).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(bindec) / Z_PARAM_STR (#20658).
 */
final class bindec extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('bindec() requires exactly one argument');
        }
        // Z_PARAM_STR $binary_string — null TypeError on 8.4 forward profile (#20658).
        $binaryString = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'bindec', 0, 'binary_string');
        VmMath::assignRadixToReturn($frame->returnVar, $binaryString, 2);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('bindec() requires exactly one argument');
        }

        // Null operand: TypeError under PROFILE=8.4 / strict_types without linking
        // MathBaseConvert (AOT IR still clears insert block on abort; #20658).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            self::jitStringArg($context, $args[0]);

            return $context->getTypeFromString('__value__*')->constNull();
        }

        return MathBaseConvert::baseToZvalCall(
            $context,
            $this->stringDataPtr(
                $context,
                self::jitStringArg($context, $args[0])
            ),
            2
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'bindec',
                0,
                'binary_string'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'bindec',
            0,
            'binary_string'
        );
    }
}
