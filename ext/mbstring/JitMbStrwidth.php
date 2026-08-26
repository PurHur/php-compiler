<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrwidth;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_strwidth() / mb_strimwidth() — MbStrwidthJitHelper in-module (#3495, #34264 / #34884).
 *
 * Runtime encoding via NestedJIT assertEncodingArgv (#34884 leftover of #34264 / peer #34875).
 */
final class JitMbStrwidth
{
    public static function strwidth(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_strwidth() requires one or two arguments');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $encLit = self::compileTimeEncoding($args, $argc, 1);
        // Only fold when encoding is a supported canon — invalid names must reach NestedJIT
        // for catchable ValueError (peer JitMbStrcut #34875; #34884).
        if (null !== $strLit && null !== $encLit && self::isSupportedEncoding($encLit)) {
            return $context->constantFromInteger(
                VmMbstring::strwidth($strLit, $encLit),
                'int64'
            );
        }

        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c; #24257).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strwidth', 0, 'string');
        $encPtr = self::linkAndEncodingPtr($context, $args, $argc, 'mb_strwidth', 1);

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
        $encLit = self::compileTimeEncoding($args, $argc, 4);
        if (
            null !== $strLit
            && null !== $fromLit
            && null !== $widthLit
            && null !== $encLit
            && self::isSupportedEncoding($encLit)
        ) {
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

        // NestedJIT helper compile can clear insert; restore before arg coerce/call (#34264 peer #34256).
        $encPtr = self::linkAndEncodingPtr($context, $args, $argc, 'mb_strimwidth', 4);
        // Runtime int ABI + boxed string return — direct call SIGSEGVs (#34264 / #3495 leftover).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrwidth::strimwidthFunction($context),
            [$str, $from, $width, $marker, $encPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * Link NestedJIT helpers, lower encoding (literal or runtime), assert when needed (#34884).
     *
     * @param list<JITVariable> $args
     * @param int               $encIndex 0-based encoding arg index (1=strwidth, 4=strimwidth)
     */
    private static function linkAndEncodingPtr(
        Context $context,
        array $args,
        int $argc,
        string $function,
        int $encIndex
    ): Value {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrwidth::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function, $encIndex);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString($function));
            $context->builder->call(
                MbStrwidth::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $encPtr;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#34884).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(
        Context $context,
        array $args,
        int $argc,
        string $function,
        int $encIndex
    ): array {
        if ($argc <= $encIndex || JITVariable::TYPE_NULL === $args[$encIndex]->type || ($args[$encIndex]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if (!self::isSupportedEncoding($encoding)) {
                $encoding = 'UTF-8';
            }

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[$encIndex]);
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
                $args[$encIndex],
                $function,
                $encIndex,
                'encoding'
            ),
            true,
        ];
    }

    /**
     * @param list<JITVariable> $args
     * @param int               $encIndex 0-based encoding arg index
     */
    private static function compileTimeEncoding(array $args, int $argc, int $encIndex): ?string
    {
        if ($argc <= $encIndex) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[$encIndex]->type || ($args[$encIndex]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[$encIndex]);
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
