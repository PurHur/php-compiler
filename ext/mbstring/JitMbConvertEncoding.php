<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_encoding() (php-src ext/mbstring/mbstring.c; #6251, #34309).
 *
 * Compile-time fold for string literals; runtime string + literal encodings via NestedJIT
 * {@see MbConvertEncodingJitHelper} (convert_kana / #34294 call shape — direct helper call).
 */
final class JitMbConvertEncoding
{
    /**
     * @param list<JITVariable> $args  Arity + null-$string already handled by caller
     */
    public static function invoke(Context $context, array $args, bool $sourceIsNull): Value
    {
        $argc = \count($args);

        $folded = self::tryCompileTimeFold($context, $args, $argc, $sourceIsNull);
        if (null !== $folded) {
            return $folded;
        }

        if (self::isArrayArg($args[0])) {
            throw new \LogicException(
                'mb_convert_encoding() array $string is not lowered for JIT/AOT in this compiler build'
            );
        }

        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        if (null === $toLit) {
            throw new \LogicException(
                'mb_convert_encoding() to_encoding must be a string literal in this compiler build'
            );
        }
        $fromIsDefault = 2 === $argc
            || (
                3 === $argc
                && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
            );
        $fromLit = null;
        if (!$fromIsDefault) {
            $fromLit = JitStringBuiltinArg::compileTimeLiteral($args[2]);
            if (null === $fromLit) {
                throw new \LogicException(
                    'mb_convert_encoding() from_encoding must be a string literal in this compiler build'
                );
            }
        } else {
            $fromLit = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
        }

        if (!self::encodingsAreValid($toLit, $fromLit)) {
            return self::foldFalse($context);
        }
        if (VmMbstring::isMbConvertPseudoEncoding($toLit) || VmMbstring::isMbConvertPseudoEncoding($fromLit)) {
            throw new \LogicException(
                'mb_convert_encoding() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
            );
        }
        if (str_contains($fromLit, ',')) {
            throw new \LogicException(
                'mb_convert_encoding() detect-then-convert from_encoding lists are not lowered for JIT/AOT runtime in this compiler build'
            );
        }

        // Soft-null DEP already emitted by caller; NestedJIT recovers '' (#21282).
        $str = $sourceIsNull
            ? $context->builder->load($context->constantStringFromString(''))
            : JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'mb_convert_encoding',
                0,
                'string'
            );

        MbConvertEncodingRuntime::ensureLinked($context);
        $toPtr = $context->builder->load($context->constantStringFromString($toLit));
        // Always pass resolved from_encoding — NestedJIT of MbstringState::internalEncoding aborts.
        $fromPtr = $context->builder->load($context->constantStringFromString($fromLit));
        $resultStr = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeFold(
        Context $context,
        array $args,
        int $argc,
        bool $sourceIsNull
    ): ?Value {
        $sourceLit = $sourceIsNull ? '' : JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $fromIsDefault = 2 === $argc
            || (
                3 === $argc
                && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
            );
        $fromLit = $fromIsDefault
            ? (MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding())
            : JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (null === $sourceLit || null === $toLit || null === $fromLit) {
            return null;
        }
        $fromList = preg_split('/\s*,\s*/', $fromLit) ?: [];
        $fromList = array_values(array_filter($fromList, static fn (string $p): bool => '' !== $p));
        if ([] === $fromList) {
            return self::foldFalse($context);
        }
        foreach ($fromList as $from) {
            if (
                !VmMbstring::isMbConvertPseudoEncoding($from)
                && null === CharsetEngine::parseEncodingSpec($from)
            ) {
                return self::foldFalse($context);
            }
        }
        if (
            !VmMbstring::isMbConvertPseudoEncoding($toLit)
            && null === CharsetEngine::parseEncodingSpec($toLit)
        ) {
            return self::foldFalse($context);
        }
        $converted = VmMbstring::convertEncodingWithFromList($sourceLit, $toLit, $fromList);
        if (false === $converted) {
            return self::foldFalse($context);
        }

        return self::foldString($context, $converted);
    }

    private static function isArrayArg(JITVariable $arg): bool
    {
        return JITVariable::TYPE_HASHTABLE === $arg->type
            || (($arg->type & JITVariable::IS_NATIVE_ARRAY) !== 0)
            || ($arg->compileTimeEmptyArrayLiteral ?? false)
            || null !== ($arg->compileTimeArray ?? null);
    }

    private static function encodingsAreValid(string $to, string $from): bool
    {
        if (
            !VmMbstring::isMbConvertPseudoEncoding($to)
            && null === CharsetEngine::parseEncodingSpec($to)
        ) {
            return false;
        }
        if (
            !VmMbstring::isMbConvertPseudoEncoding($from)
            && null === CharsetEngine::parseEncodingSpec($from)
        ) {
            return false;
        }

        return true;
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function foldString(Context $context, string $converted): Value
    {
        $strPtr = $context->builder->load($context->constantStringFromString($converted));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $strPtr
        );

        return $ptr;
    }
}
