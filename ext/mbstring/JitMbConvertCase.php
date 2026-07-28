<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_convert_case() (issue #7014).
 */
final class JitMbConvertCase
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === ($args[0]->compileTimeString ?? null)) {
            return null;
        }
        $mode = self::compileTimeMode($context, $args[1]);
        if (null === $mode) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding) {
            return null;
        }

        VmMbstring::validateMode($mode, 'mb_convert_case', 1);
        $result = VmMbstring::convertCase($args[0]->compileTimeString, $mode, $encoding);

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function lowerRuntime(Context $context, array $args): Value
    {
        $mode = self::compileTimeMode($context, $args[1]);
        if (null === $mode) {
            throw new \LogicException(
                'mb_convert_case() JIT requires compile-time MB_CASE_* mode in this compiler build'
            );
        }
        VmMbstring::validateMode($mode, 'mb_convert_case', 1);

        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding || ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding)) {
            throw new \LogicException(
                'mb_convert_case() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (#21313).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_convert_case', 0, 'string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER,
            MbstringConstants::MB_CASE_UPPER_SIMPLE => self::lowerToUpper($context, $copy),
            MbstringConstants::MB_CASE_LOWER,
            MbstringConstants::MB_CASE_LOWER_SIMPLE,
            MbstringConstants::MB_CASE_FOLD,
            MbstringConstants::MB_CASE_FOLD_SIMPLE => self::upperToLower($context, $copy),
            MbstringConstants::MB_CASE_TITLE,
            MbstringConstants::MB_CASE_TITLE_SIMPLE => self::asciiTitle($context, $copy),
            default => throw new \ValueError(
                'mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'
            ),
        };
    }

    private static function lowerToUpper(Context $context, Value $copy): Value
    {
        lcfirst::transformAllAscii($context, $copy, ord('a'), ord('z'), -32);

        return $copy;
    }

    private static function upperToLower(Context $context, Value $copy): Value
    {
        lcfirst::transformAllAscii($context, $copy, ord('A'), ord('Z'), 32);

        return $copy;
    }

    private static function asciiTitle(Context $context, Value $copy): Value
    {
        lcfirst::transformAllAscii($context, $copy, ord('A'), ord('Z'), 32);
        lcfirst::transformFirstAscii($context, $copy, ord('a'), ord('z'), -32);

        return $copy;
    }

    private static function compileTimeMode(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== ($arg->compileTimeInteger ?? null)) {
            return $arg->compileTimeInteger;
        }
        if (null !== $arg->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
            if (null !== $phpVar && VmVariable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }
}
