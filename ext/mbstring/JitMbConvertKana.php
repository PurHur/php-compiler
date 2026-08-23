<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertKanaRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_convert_kana() — compile-time fold + NestedJIT (#34294 / #13099).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
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
            throw new \LogicException('mb_convert_kana() requires between 1 and 3 arguments');
        }

        $folded = self::tryCompileTimeFold($context, $args, $argc);
        if (null !== $folded) {
            return $folded;
        }

        return self::lowerRuntime($context, $args, $argc);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(Context $context, array $args, int $argc): ?Value
    {
        // Soft-null DEP+coerce on 8.4 — do not fold; NestedJIT / VM (#24209).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $strLit = $args[0]->compileTimeString ?? null;
        if (null === $strLit) {
            return null;
        }

        $option = null;
        $optionOmitted = true;
        if ($argc >= 2) {
            $optionOmitted = false;
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                return null;
            }
            if (JITVariable::TYPE_STRING !== $args[1]->type) {
                return null;
            }
            $option = $args[1]->compileTimeString ?? null;
            if (null === $option) {
                return null;
            }
        }

        $encoding = self::compileTimeEncoding($args, $argc);
        if (null === $encoding) {
            return null;
        }

        try {
            $result = $optionOmitted
                ? KanaConvert::convert($strLit, null, $encoding)
                : KanaConvert::convert($strLit, $option, $encoding);
        } catch (\ValueError|\LogicException $e) {
            // Invalid mode / unsupported encoding — lower via NestedJIT so user try/catch works.
            unset($e);

            return null;
        }

        return self::materializeOwnedString(
            $context,
            $context->builder->load($context->constantStringFromString($result))
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function lowerRuntime(Context $context, array $args, int $argc): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertKanaRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_convert_kana',
            0,
            'string'
        );
        $encoding = self::runtimeEncodingLiteral($args, $argc, $context);
        self::assertSupportedEncoding($encoding);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        if ($argc < 2) {
            $resultStr = $context->builder->call(
                MbConvertKanaRuntime::convertDefaultHelper($context),
                $str,
                $encPtr
            );
        } else {
            $mode = JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'mb_convert_kana',
                1,
                'mode'
            );
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
    private static function runtimeEncodingLiteral(array $args, int $argc, Context $context): string
    {
        unset($context);
        // php-src / VM: omitted encoding defaults to UTF-8 (not mb_internal_encoding).
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
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_convert_kana() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
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
}
