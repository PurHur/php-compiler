<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertCaseRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_convert_case() (issue #7014, NestedJIT Unicode #34280 / #34284).
 *
 * UPPER/LOWER/FOLD runtime delegates to {@see JitMbCase}. TITLE/TITLE_SIMPLE use
 * {@see MbConvertCaseJitHelper::titleArgv} / {@see MbConvertCaseJitHelper::titleSimpleArgv}
 * (ASCII peel left ü uncased — #34284). Compile-time folds use {@see VmMbstring::convertCase}.
 * TITLE runtime encoding via NestedJIT assertEncodingArgv (#35151 leftover of #34284 / peer #34858).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
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
            MbstringConstants::MB_CASE_TITLE => self::invokeTitle($context, $caseArgs, false),
            MbstringConstants::MB_CASE_TITLE_SIMPLE => self::invokeTitle($context, $caseArgs, true),
            default => throw new \ValueError(
                'mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants'
            ),
        };
    }

    /**
     * Rebuild (string[, encoding]) args for {@see JitMbCase} / TITLE helpers — drop mode at index 1.
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
     * TITLE / TITLE_SIMPLE via NestedJIT (peer {@see JitMbCase}; #34284).
     * Runtime encoding via NestedJIT assert (#35151 leftover of #34284 / peer #34858).
     *
     * @param list<JITVariable> $args
     */
    private static function invokeTitle(Context $context, array $args, bool $simple): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_convert_case() TITLE requires one or two string args after mode');
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #34284).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertCaseRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_case_title_runtime');

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_convert_case', 0, 'string');
        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);

        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_convert_case'));
            $context->builder->call(
                MbConvertCaseRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        $helper = $simple
            ? MbConvertCaseRuntime::titleSimpleHelper($context)
            : MbConvertCaseRuntime::titleHelper($context);
        $resultStr = $context->builder->call($helper, $str, $encPtr);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35151).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc): array
    {
        if ($argc < 2 || JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            self::assertSupportedEncoding($encoding);

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null !== $canonical && self::isSupportedEncoding($canonical)) {
                return [$context->builder->load($context->constantStringFromString($canonical)), false];
            }
            // Invalid / unsupported literal — NestedJIT assert throws catchable ValueError (#35151).
            return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $args[1],
                'mb_convert_case',
                2,
                'encoding'
            ),
            true,
        ];
    }

    private static function isSupportedEncoding(string $encoding): bool
    {
        return 'UTF-8' === $encoding || 'ASCII' === $encoding || '8BIT' === $encoding;
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if (!self::isSupportedEncoding($encoding)) {
            throw new \LogicException(
                'mb_convert_case() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
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
