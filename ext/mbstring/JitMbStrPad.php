<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\MbStrwidth;
use PHPCompiler\JIT\Builtin\PadTypeJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitIntdiv;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for mb_str_pad() — MbStrwidthJitHelper in-module (#6081). */
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
        $encLit = $argc >= 5 ? ($args[4]->compileTimeString ?? 'UTF-8') : 'UTF-8';
        if (null !== $inputLit && null !== $lengthLit && null !== $encLit && null !== $padTypeLit) {
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
        if ($argc >= 5) {
            if (JITVariable::TYPE_STRING !== $args[4]->type) {
                throw new \LogicException('mb_str_pad() encoding must be a string literal in this compiler build');
            }
            $encoding = $args[4]->compileTimeString ?? 'UTF-8';
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        MbStrwidth::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $resultStr = $context->builder->call(
            MbStrwidth::strPadFunction($context),
            $input,
            $length,
            $padString,
            $padType,
            $encPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_str_pad() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
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
