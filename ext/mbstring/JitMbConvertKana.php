<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertKanaRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_kana() (php-src ext/mbstring/mbstring.c; #13099, #34294).
 *
 * Runtime encoding via NestedJIT assert + convert without encoding param (#35193).
 * NestedJIT KanaConvert SIGSEGVs if the convert frame received a runtime encoding ptr.
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

        // Prefer: runtime encoding assert + compile-time materialize when string/mode fold (#35193).
        $materialized = self::tryAssertAndMaterialize($context, $args, $argc);
        if (null !== $materialized) {
            return $materialized;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertKanaRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_kana_runtime');

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_convert_kana', 0, 'string');
        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_convert_kana'));
            JitNestedHelperCoerce::callHelper(
                $context,
                MbConvertKanaRuntime::assertEncodingHelper($context),
                [$encPtr, $fnName]
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_kana_after_enc_assert');
            $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_convert_kana', 0, 'string');
        }

        // Convert helpers take string[/mode] only — no encoding param (#35193).
        if ($argc < 2) {
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbConvertKanaRuntime::convertDefaultHelper($context),
                [$str]
            );
        } else {
            $mode = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_convert_kana', 1, 'mode');
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbConvertKanaRuntime::convertHelper($context),
                [$str, $mode]
            );
        }
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * When string+mode are compile-time and encoding needs runtime assert: emit assert,
     * then materialize KanaConvert result computed in the compiler process (#35193).
     *
     * @param list<JITVariable> $args
     */
    private static function tryAssertAndMaterialize(Context $context, array $args, int $argc): ?Value
    {
        // Decide foldability before NestedJIT / lowering.
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertKanaRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_kana_assert_materialize');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if (!$needsAssert) {
            return null;
        }

        $fnName = $context->builder->load($context->constantStringFromString('mb_convert_kana'));
        JitNestedHelperCoerce::callHelper(
            $context,
            MbConvertKanaRuntime::assertEncodingHelper($context),
            [$encPtr, $fnName]
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_kana_after_assert_materialize');

        return self::materializeString($context, KanaConvert::convert($strLit, $option, 'UTF-8'));
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
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
        if (null === $encoding || !self::isSupportedEncoding($encoding)) {
            return null;
        }

        return self::materializeString($context, KanaConvert::convert($strLit, $option, $encoding));
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
        $lit = JitStringArg::compileTimeLiteral($args[2]);
        if (null === $lit) {
            return null;
        }
        $canonical = MbstringEncodingRegistry::resolve($lit);

        return null !== $canonical ? $canonical : $lit;
    }

    /**
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool}
     */
    private static function encodingPtr(Context $context, array $args, int $argc): array
    {
        if ($argc < 3 || JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return [$context->builder->load($context->constantStringFromString('UTF-8')), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null !== $canonical && self::isSupportedEncoding($canonical)) {
                return [$context->builder->load($context->constantStringFromString($canonical)), false];
            }

            return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $args[2],
                'mb_convert_kana',
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
