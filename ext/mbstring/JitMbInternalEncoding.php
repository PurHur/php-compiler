<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbInternalEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_internal_encoding() (#20014, #35221 runtime encoding).
 *
 * Compile-time literal set still updates {@see MbstringAotFoldState} for other mb_* folds;
 * getter/setter always NestedJIT so runtime set + subsequent get match Zend
 * (peer {@see JitMbPreferredMimeName} / {@see DefaultTimezoneJitHelper}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_internal_encoding)
 */
final class JitMbInternalEncoding
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_internal_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || JITVariable::TYPE_NULL === $args[0]->type
            || ($args[0]->isNullConstant ?? false)
        ) {
            return self::lowerGet($context);
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null !== $canonical) {
                MbstringAotFoldState::setInternalEncoding($context, $canonical);
            }
            // Invalid / valid literal both go NestedJIT — catchable ValueError (#35221).
        }

        return self::lowerSet($context, $args[0], $encodingLit);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbInternalEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_internal_encoding_get');

        // NestedJIT string → `__value__*` (raw `__string__*` ABI empties under thin AOT —
        // peer {@see DefaultTimezoneRuntime} / {@see JitMbPreferredMimeName} / #33950).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbInternalEncodingRuntime::getHelper($context),
            []
        );
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $strPtr
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    private static function lowerSet(Context $context, JITVariable $encodingArg, ?string $encodingLit): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbInternalEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_internal_encoding_set');

        $enc = null !== $encodingLit
            ? $context->builder->load($context->constantStringFromString($encodingLit))
            : JitStringBuiltinArg::lower(
                $context,
                $encodingArg,
                'mb_internal_encoding',
                0,
                'encoding'
            );

        $ok = $context->builder->call(
            MbInternalEncodingRuntime::setHelper($context),
            $enc
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $ok, $zero);
    }
}
