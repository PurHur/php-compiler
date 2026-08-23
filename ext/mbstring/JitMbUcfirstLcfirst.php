<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\MbCaseRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_ucfirst() / mb_lcfirst() (php-src ext/mbstring/mbstring.c; #27330, #34259).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbCaseJitHelper} (peer {@see JitMbCase}).
 */
final class JitMbUcfirstLcfirst
{
    /**
     * @param list<JITVariable> $args
     * @param callable(string, string): string $fold
     */
    public static function invoke(Context $context, string $function, callable $fold, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($function.'() requires one or two arguments');
        }

        // Soft-null — do not fold; recover via NestedJIT / VM (#24176).
        $nullInput = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $strLit = $nullInput ? null : ($args[0]->compileTimeString ?? null);
        $encLit = self::compileTimeEncoding($args, $argc);
        if (null !== $strLit && null !== $encLit) {
            return self::materializeString($context, $fold($strLit, $encLit));
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'string');
        $encoding = self::runtimeEncodingLiteral($args, $argc, $context);
        self::assertSupportedEncoding($encoding);

        MbCaseRuntime::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $helper = 'mb_ucfirst' === $function
            ? MbCaseRuntime::ucfirstHelper($context)
            : MbCaseRuntime::lcfirstHelper($context);
        $resultStr = $context->builder->call($helper, $str, $encPtr);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 2) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            return null;
        }

        return $args[1]->compileTimeString ?? null;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeEncodingLiteral(array $args, int $argc, Context $context): string
    {
        if ($argc < 2) {
            return MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException(
                'mb_ucfirst()/mb_lcfirst() encoding must be a string literal in this compiler build'
            );
        }
        $encoding = $args[1]->compileTimeString ?? null;
        if (null === $encoding) {
            throw new \LogicException(
                'mb_ucfirst()/mb_lcfirst() encoding must be a string literal in this compiler build'
            );
        }

        return $encoding;
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_ucfirst()/mb_lcfirst() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
    }

    private static function materializeString(Context $context, string $str): Value
    {
        return self::materializeOwnedString(
            $context,
            $context->builder->load($context->constantStringFromString($str))
        );
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
