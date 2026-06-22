<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for number_format() (int/float/numeric string, 0–4 args; subset of PHP).
 *
 * php-src: ext/standard/number_format.c — Z_PARAM_LONG / Z_PARAM_STR
 */
final class JitNumberFormat
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 4) {
            throw new \LogicException('number_format() requires one to four arguments');
        }

        $number = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'number_format', 'float');
        $i64 = $context->getTypeFromString('int64');
        $decimals = ($argc >= 2 && !NamedOptionalCallArgs::isOmittedOptional($args[1]))
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'number_format', 2, 'decimals')
            : $i64->constInt(0, false);
        $decSep = ($argc >= 3 && !NamedOptionalCallArgs::isOmittedOptional($args[2]))
            ? JitStringBuiltinArg::lower($context, $args[2], 'number_format', 2, 'decimal_separator', '?string')
            : $context->builder->load($context->constantStringFromString('.'));
        $thouSep = (4 === $argc && !NamedOptionalCallArgs::isOmittedOptional($args[3]))
            ? JitStringBuiltinArg::lower($context, $args[3], 'number_format', 3, 'thousands_separator', '?string')
            : $context->builder->load($context->constantStringFromString(','));

        return $context->builder->call(
            $context->lookupFunction('__compiler_number_format'),
            $number,
            $decimals,
            $decSep,
            $thouSep
        );
    }

}
