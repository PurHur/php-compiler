<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * number_format() for integers and floats (C-style locale subset; LLVM JIT/AOT).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_LONG / Z_PARAM_STR / RoundingMode
 */
final class number_format extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 5 : 4;
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > $maxArgs) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at most %d arguments, %d given',
                $maxArgs,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame) && Variable::TYPE_NULL === $numVar->type) {
            throw new \TypeError('number_format(): Argument #1 ($num) must be of type float, null given');
        }
        $num = VmNumberFormat::coerceFloat($numVar);
        $decimals = isset($frame->calledArgs[1])
            ? VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'number_format',
                2,
                'decimals'
            )
            : 0;
        $decimalSeparator = isset($frame->calledArgs[2])
            ? VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[2],
                'number_format',
                2,
                'decimal_separator'
            ) ?? '.'
            : '.';
        $thousandsSeparator = isset($frame->calledArgs[3])
            ? VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[3],
                'number_format',
                3,
                'thousands_separator'
            ) ?? ','
            : ',';
        $roundingMode = StdlibConstants::PHP_ROUND_HALF_UP;
        if (CompilerVersion::supportsRoundingModeEnum() && isset($frame->calledArgs[4])) {
            $roundingMode = VmRoundMode::resolveRoundModeArg(
                $frame->calledArgs[4]->resolveIndirect(),
                'number_format',
                'rounding_mode',
                5
            );
        }
        $frame->returnVar->string(VmNumberFormat::format(
            $num,
            $decimals,
            $decimalSeparator,
            $thousandsSeparator,
            $roundingMode
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $maxArgs = CompilerVersion::supportsRoundingModeEnum() ? 5 : 4;
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if ($argc > $maxArgs) {
            throw new \ArgumentCountError(\sprintf(
                'number_format() expects at most %d arguments, %d given',
                $maxArgs,
                $argc
            ));
        }

        return JitNumberFormat::format($context, ...$args);
    }
}
