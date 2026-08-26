<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrwidth;
use PHPCompiler\JIT\Builtin\PadTypeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitIntdiv;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_str_pad() — MbStrwidthJitHelper in-module (#6081, #34270, #35187).
 *
 * Runtime int length must go through {@see JitNestedHelperCoerce::callHelper} (raw call SIGSEGVs).
 * Runtime encoding via NestedJIT assertEncodingArgv (#35187 leftover of #34270 / peer #34884).
 */
final class JitMbStrPad
{
    public static function pad(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('mb_str_pad() requires two to five arguments');
        }

        // Zend 8.4 soft-null + DEP (#24176). Do not compile-time fold null→'' —
        // empty pad fold currently segfaults under thin AOT; use soft-null lower.
        $nullInput = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $inputLit = $nullInput ? null : ($args[0]->compileTimeString ?? null);
        $lengthLit = self::compileTimeInt($context, $args[1]);
        $padLit = $argc >= 3 ? ($args[2]->compileTimeString ?? ' ') : ' ';
        // Default STR_PAD_RIGHT; null when argc>=4 but pad_type is not foldable yet (#27435).
        $padTypeLit = 1;
        if ($argc >= 4) {
            $padTypeLit = PadTypeJit::compileTimePadType($context, $args[3]);
        }
        $encLit = self::compileTimeEncoding($args, $argc);
        // Only fold when encoding is a supported canon — invalid names must reach NestedJIT
        // for catchable ValueError (peer JitMbStrwidth #34884; #35187).
        if (
            null !== $inputLit
            && null !== $lengthLit
            && null !== $encLit
            && null !== $padTypeLit
            && self::isSupportedEncoding($encLit)
        ) {
            return self::materializeString(
                $context,
                VmMbstring::strPad($inputLit, $lengthLit, $padLit, $padTypeLit, $encLit)
            );
        }

        $input = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_str_pad', 0, 'string');
        $length = JitStrictIntArg::lower($context, $args[1], 'mb_str_pad', 2, 'length');
        if ($argc >= 3) {
            $padString = JitStringBuiltinArg::lower($context, $args[2], 'mb_str_pad', 2, 'pad_string');
        } else {
            $padString = $context->builder->load($context->constantStringFromString(' '));
        }
        if ($argc >= 4) {
            $padTypeLiteral = PadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeLiteral) {
                $padType = $context->getTypeFromString('int64')->constInt($padTypeLiteral, false);
            } else {
                $padType = JitIntdiv::lowerIntBuiltinArg($context, $args[3], 'mb_str_pad', 4, 'pad_type');
            }
        } else {
            $padType = $context->getTypeFromString('int64')->constInt(1, false);
        }

        // NestedJIT helper compile can clear insert; restore before arg coerce/call (#34270 peer #34264).
        $encPtr = self::linkAndEncodingPtr($context, $args, $argc);
        // Runtime int ABI + boxed string return — direct call SIGSEGVs (#34270 / #6081 leftover).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrwidth::strPadFunction($context),
            [$input, $length, $padString, $padType, $encPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * Link NestedJIT helpers, lower encoding (literal or runtime), assert when needed (#35187).
     *
     * @param list<JITVariable> $args
     */
    private static function linkAndEncodingPtr(Context $context, array $args, int $argc): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrwidth::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_str_pad_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_str_pad'));
            $context->builder->call(
                MbStrwidth::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $encPtr;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35187).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc): array
    {
        if ($argc < 5 || JITVariable::TYPE_NULL === $args[4]->type || ($args[4]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if (!self::isSupportedEncoding($encoding)) {
                $encoding = 'UTF-8';
            }

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[4]);
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
                $args[4],
                'mb_str_pad',
                4,
                'encoding'
            ),
            true,
        ];
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 5) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[4]->type || ($args[4]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[4]);
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
        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $ptr = \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function compileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }
}
