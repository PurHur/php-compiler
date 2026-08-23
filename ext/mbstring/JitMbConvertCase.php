<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_convert_case() (issue #7014, NestedJIT Unicode #34280).
 *
 * UPPER/LOWER/FOLD runtime delegates to {@see JitMbCase} — ASCII-only transformAllAscii
 * left ü uncased (#34280). TITLE keeps the historic ASCII peel (same as pre-#34280 AOT).
 * Compile-time folds use {@see VmMbstring::convertCase}.
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

        return self::materializeOwnedString(
            $context,
            $context->builder->load($context->constantStringFromString($result))
        );
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

        $caseArgs = self::caseArgsForJitMbCase($args);

        return match ($mode) {
            MbstringConstants::MB_CASE_UPPER,
            MbstringConstants::MB_CASE_UPPER_SIMPLE => JitMbCase::invokeStrtoupper($context, $caseArgs),
            MbstringConstants::MB_CASE_LOWER,
            MbstringConstants::MB_CASE_LOWER_SIMPLE,
            MbstringConstants::MB_CASE_FOLD,
            MbstringConstants::MB_CASE_FOLD_SIMPLE => JitMbCase::invokeStrtolower($context, $caseArgs),
            MbstringConstants::MB_CASE_TITLE,
            MbstringConstants::MB_CASE_TITLE_SIMPLE => self::asciiTitleRuntime($context, $args[0]),
            default => throw new \ValueError(
                'mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'
            ),
        };
    }

    /**
     * Rebuild (string[, encoding]) args for {@see JitMbCase} — drop mode at index 1.
     *
     * @param JITVariable[] $args
     *
     * @return list<JITVariable>
     */
    private static function caseArgsForJitMbCase(array $args): array
    {
        if (isset($args[2])) {
            return [$args[0], $args[2]];
        }

        return [$args[0]];
    }

    /**
     * Pre-#34280 TITLE peel (ASCII only). Full Unicode titlecase remains on the fold/VM path.
     */
    private static function asciiTitleRuntime(Context $context, JITVariable $stringArg): Value
    {
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $stringArg, 'mb_convert_case', 0, 'string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        lcfirst::transformAllAscii($context, $copy, ord('A'), ord('Z'), 32);
        lcfirst::transformFirstAscii($context, $copy, ord('a'), ord('z'), -32);

        return self::materializeOwnedString($context, $copy);
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
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
