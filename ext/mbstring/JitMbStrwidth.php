<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Builtin\MbStrwidth;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for mb_strwidth() — MbStrwidthJitHelper in-module (#3495). */
final class JitMbStrwidth
{
    public static function strwidth(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strwidth() requires one or two arguments');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $encLit = 2 === $argc ? ($args[1]->compileTimeString ?? null) : 'UTF-8';
        if (null !== $strLit && null !== $encLit) {
            return $context->constantFromInteger(
                VmMbstring::strwidth($strLit, $encLit),
                'int64'
            );
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#21061).
        $str = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_strwidth', 0, 'string');
        if (2 === $argc) {
            if (JITVariable::TYPE_STRING !== $args[1]->type) {
                throw new \LogicException('mb_strwidth() encoding must be a string literal in this compiler build');
            }
            $encoding = $args[1]->compileTimeString ?? 'UTF-8';
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        MbStrwidth::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        return $context->builder->call(
            MbStrwidth::strwidthFunction($context),
            $str,
            $encPtr
        );
    }

    public static function strimwidth(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException('mb_strimwidth() requires three to five arguments');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $fromLit = self::compileTimeInt($context, $args[1]);
        $widthLit = self::compileTimeInt($context, $args[2]);
        $markerLit = $argc >= 4 ? ($args[3]->compileTimeString ?? '') : '';
        $encLit = 5 === $argc ? ($args[4]->compileTimeString ?? null) : 'UTF-8';
        if (null !== $strLit && null !== $fromLit && null !== $widthLit && null !== $encLit) {
            return self::materializeString(
                $context,
                VmMbstring::strimwidth($strLit, $fromLit, $widthLit, $markerLit, $encLit)
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21430).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strimwidth', 0, 'string');
        $from = JitStrictIntArg::lower($context, $args[1], 'mb_strimwidth', 2, 'start');
        $width = JitStrictIntArg::lower($context, $args[2], 'mb_strimwidth', 3, 'width');
        if ($argc >= 4) {
            $marker = JitStringBuiltinArg::lower($context, $args[3], 'mb_strimwidth', 4, 'trim_marker');
        } else {
            $marker = $context->builder->load($context->constantStringFromString(''));
        }
        if (5 === $argc) {
            if (JITVariable::TYPE_STRING !== $args[4]->type) {
                throw new \LogicException('mb_strimwidth() encoding must be a string literal in this compiler build');
            }
            $encoding = $args[4]->compileTimeString ?? 'UTF-8';
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        MbStrwidth::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $resultStr = $context->builder->call(
            MbStrwidth::strimwidthFunction($context),
            $str,
            $from,
            $width,
            $marker,
            $encPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_strwidth() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
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
