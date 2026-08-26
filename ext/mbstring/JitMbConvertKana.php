<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertKanaRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_kana() (php-src ext/mbstring/mbstring.c; #13099, #34294, #35193).
 *
 * Compile-time fold via {@see KanaConvert}. Runtime encoding with foldable string/mode: NestedJIT
 * {@see MbConvertKanaJitHelper::assertEncodingArgv} then materialize the UTF-8-core fold result
 * (ASCII/8BIT/UTF-8 share the same kana core — #35193 leftover of #34294 / peer #35151).
 *
 * Full NestedJIT of {@see KanaConvert} is not thin-AOT safe (module verify / SIGSEGV); runtime
 * string/mode remains an honest LogicException until a NestedJIT-safe port lands.
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

        $partial = self::tryFoldableStringModeWithEncodingGate($context, $args, $argc);
        if (null !== $partial) {
            return $partial;
        }

        throw new \LogicException(
            'mb_convert_kana() requires compile-time string/mode in this compiler build (NestedJIT kana convert is not thin-AOT safe; #34294/#35193)'
        );
    }

    /**
     * Foldable string + mode, encoding omitted / literal / runtime — assert when needed, convert
     * via {@see KanaConvert} (NestedJIT {@see MbConvertKanaJitHelper::convertArgv} for runtime enc).
     *
     * @param list<JITVariable> $args
     */
    private static function tryFoldableStringModeWithEncodingGate(
        Context $context,
        array $args,
        int $argc
    ): ?Value {
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

        // Link NestedJIT assert before lowering encoding (#34270 / #35193).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertKanaRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_kana_encoding_gate');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_convert_kana'));
            $context->builder->call(
                MbConvertKanaRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
            $utf8 = KanaConvert::convert($strLit, $option, 'UTF-8');
            $ascii = KanaConvert::convert($strLit, $option, 'ASCII');
            $eight = KanaConvert::convert($strLit, $option, '8BIT');
            $resultStr = $context->builder->call(
                MbConvertKanaRuntime::selectHelper($context),
                $encPtr,
                $context->builder->load($context->constantStringFromString($utf8)),
                $context->builder->load($context->constantStringFromString($ascii)),
                $context->builder->load($context->constantStringFromString($eight))
            );

            return self::materializeOwnedString($context, $resultStr);
        }

        $encoding = self::compileTimeEncoding($args, $argc);
        if (null === $encoding || !self::isSupportedEncoding($encoding)) {
            return null;
        }

        return self::materializeString($context, KanaConvert::convert($strLit, $option, $encoding));
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35193).
     *
     * Omitted encoding is UTF-8 (match {@see mb_convert_kana::execute}, not internal).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
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
        if (null === $encoding) {
            return null;
        }
        if (!self::isSupportedEncoding($encoding)) {
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
