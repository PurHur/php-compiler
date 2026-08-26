<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_convert_encoding() (php-src ext/mbstring/mbstring.c; #6251, #34309).
 *
 * Compile-time fold for string literals; runtime string + encodings via NestedJIT
 * {@see MbConvertEncodingJitHelper} (#35165 leftover of #34309 / peer #35161).
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

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35165).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbConvertEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_runtime');

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

        [$toPtr, $assertTo] = self::toEncodingPtr($context, $args[1]);
        if ($assertTo) {
            $context->builder->call(
                MbConvertEncodingRuntime::assertToEncodingHelper($context),
                $toPtr
            );
        }

        $fromIsDefault = 2 === $argc
            || (
                3 === $argc
                && (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant)
            );
        if ($fromIsDefault) {
            $fromLit = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            // Always pass resolved from_encoding — NestedJIT of MbstringState::internalEncoding aborts.
            $fromPtr = $context->builder->load($context->constantStringFromString($fromLit));
        } else {
            [$fromPtr, $assertFrom] = self::fromEncodingPtr($context, $args[2]);
            if ($assertFrom) {
                $context->builder->call(
                    MbConvertEncodingRuntime::assertFromEncodingHelper($context),
                    $fromPtr
                );
            }
        }

        $resultStr = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * Literal leaf encoding → constant string (no assert); otherwise NestedJIT + assert (#35165).
     *
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function toEncodingPtr(Context $context, JITVariable $arg): array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            if (VmMbstring::isMbConvertPseudoEncoding($encodingLit)) {
                throw new \LogicException(
                    'mb_convert_encoding() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (self::isLeafEncoding($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
            }
            if (null === CharsetEngine::parseEncodingSpec($encodingLit)) {
                // Known-invalid literal: assert → Zend ValueError (#35165).
                return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
            }
            // Non-leaf but CharsetEngine-valid (e.g. UTF-16): NestedJIT leaf returns ''.
            return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_convert_encoding',
                1,
                'to_encoding'
            ),
            true,
        ];
    }

    /**
     * @return array{0: Value, 1: bool}
     */
    private static function fromEncodingPtr(Context $context, JITVariable $arg): array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            if (VmMbstring::isMbConvertPseudoEncoding($encodingLit)) {
                throw new \LogicException(
                    'mb_convert_encoding() pseudo encodings are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (str_contains($encodingLit, ',')) {
                throw new \LogicException(
                    'mb_convert_encoding() detect-then-convert from_encoding lists are not lowered for JIT/AOT runtime in this compiler build'
                );
            }
            if (self::isLeafEncoding($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
            }
            if (null === CharsetEngine::parseEncodingSpec($encodingLit)) {
                return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
            }

            return [$context->builder->load($context->constantStringFromString($encodingLit)), false];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_convert_encoding',
                2,
                'from_encoding'
            ),
            true,
        ];
    }

    private static function isLeafEncoding(string $encoding): bool
    {
        $e = strtoupper($encoding);

        return 'UTF8' === $e || 'UTF-8' === $e
            || 'LATIN1' === $e || 'LATIN-1' === $e || 'ISO-8859-1' === $e
            || 'ASCII' === $e || 'US-ASCII' === $e;
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
