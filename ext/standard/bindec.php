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
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * bindec() for string arguments (subset of PHP standard library).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(bindec) / Z_PARAM_STR (#20658, #21244 soft-null).
 */
final class bindec extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#28476).
        $this->requireExactArgCount($frame, 'bindec', 1);
        $binaryString = self::vmStringArg($frame, 0);
        VmMath::assignRadixToReturn($frame->returnVar, $binaryString, 2);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT/JIT) — #28476.
        if (!$this->requireExactJitArgCount($context, $args, 'bindec', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }

        if (
            !$context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            self::jitStringArg($context, $args[0]);
            if ($args[0]->isNullConstant ?? false) {
                return JitValueBox::coerceToValuePtrForStore(
                    $context,
                    $context->getTypeFromString('int64')->constInt(0, false)
                );
            }
        }

        return MathBaseConvert::baseToZvalCall(
            $context,
            self::jitStringArg($context, $args[0]),
            2
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'bindec', 'binary_string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'bindec',
            0,
            'binary_string'
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'bindec',
            0,
            'binary_string'
        );
    }
}
