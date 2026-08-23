<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\MbConvertKanaRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_kana() (php-src ext/mbstring/mbstring.c; #13099, #34294).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbConvertKanaJitHelper}.
 */
final class JitMbConvertKana
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mb_convert_kana() requires one to three arguments');
        }

        $folded = self::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c / #24209).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_convert_kana', 0, 'string');
        $encoding = self::runtimeEncodingLiteral($args, $argc);
        self::assertSupportedEncoding($encoding);

        MbConvertKanaRuntime::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        if ($argc < 2) {
            $resultStr = $context->builder->call(
                MbConvertKanaRuntime::convertDefaultHelper($context),
                $str,
                $encPtr
            );
        } else {
            $mode = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_convert_kana', 1, 'mode');
            $resultStr = $context->builder->call(
                MbConvertKanaRuntime::convertHelper($context),
                $str,
                $mode,
                $encPtr
            );
        }

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        // Soft-null — do not fold; recover via NestedJIT (#24209).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $strLit = $args[0]->compileTimeString ?? null;
        if (null === $strLit) {
            return null;
        }

        $option = null;
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $option = '';
            } elseif (JITVariable::TYPE_STRING !== $args[1]->type) {
                return null;
            } else {
                $option = $args[1]->compileTimeString ?? null;
                if (null === $option) {
                    return null;
                }
            }
        }

        $encoding = self::compileTimeEncoding($args, $argc);
        if (null === $encoding) {
            return null;
        }

        return self::materializeString($context, KanaConvert::convert($strLit, $option, $encoding));
    }

    /**
     * Match {@see mb_convert_kana::execute}: omitted encoding is UTF-8 (not internal).
     *
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 3) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[2]->type) {
            return null;
        }

        return $args[2]->compileTimeString ?? null;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeEncodingLiteral(array $args, int $argc): string
    {
        if ($argc < 3) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[2]->type) {
            throw new \LogicException(
                'mb_convert_kana() encoding must be a string literal in this compiler build'
            );
        }
        $encoding = $args[2]->compileTimeString ?? null;
        if (null === $encoding) {
            throw new \LogicException(
                'mb_convert_kana() encoding must be a string literal in this compiler build'
            );
        }

        return $encoding;
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        $canonical = MbstringEncodingRegistry::resolve($encoding) ?? $encoding;
        if ('UTF-8' !== $canonical && 'ASCII' !== $canonical && '8BIT' !== $canonical) {
            throw new \LogicException(
                'mb_convert_kana() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
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
