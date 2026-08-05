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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * number_format() for integers and floats (C-style locale subset; LLVM JIT/AOT).
 *
 * php-src: ext/standard/number_format.c — arity 1–4 (no RoundingMode; #23575)
 */
final class number_format extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmNumberFormat::assertArgCount($argc);
        if (null === $frame->returnVar) {
            return;
        }
        $num = VmNumberFormat::coerceFloat($frame->calledArgs[0]->resolveIndirect(), $frame);
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
        $frame->returnVar->string(VmNumberFormat::format(
            $num,
            $decimals,
            $decimalSeparator,
            $thousandsSeparator,
            StdlibConstants::PHP_ROUND_HALF_UP
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        JitNumberFormat::assertArgCount($context, ...$args);

        return JitNumberFormat::format($context, ...$args);
    }
}
