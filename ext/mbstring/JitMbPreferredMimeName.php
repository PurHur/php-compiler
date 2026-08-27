<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbPreferredMimeNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_preferred_mime_name() (php-src ext/mbstring/mbstring.c; #13100, #34298, #35275).
 *
 * Compile-time fold for string literals; runtime via leaf NestedJIT
 * {@see MbPreferredMimeNameJitHelper} (peer {@see JitMbEncodingRegistry} / #35216).
 * `ensureLinked` runs **before** arg lowering (peer #34270).
 */
final class JitMbPreferredMimeName
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \LogicException('mb_preferred_mime_name() requires exactly one argument');
        }

        $folded = self::tryCompileTimeFold($context, $args[0]);
        if (null !== $folded) {
            return $folded;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbPreferredMimeNameRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_preferred_mime_runtime');

        $encoding = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'mb_preferred_mime_name',
            0,
            'encoding'
        );
        $context->builder->call(MbPreferredMimeNameRuntime::assertEncodingHelper($context), $encoding);
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbPreferredMimeNameRuntime::preferredHelper($context),
            [$encoding]
        );

        return self::boxStringOrEmptyFalse($context, $raw);
    }

    private static function tryCompileTimeFold(Context $context, JITVariable $arg): ?Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null === $encodingLit) {
            return null;
        }

        $canonical = MbstringEncodingRegistry::resolve($encodingLit);
        if (null === $canonical) {
            return JitMbEncodingRegistry::emitPreferredMimeValueError(
                $context,
                sprintf(
                    'mb_preferred_mime_name(): Argument #1 ($encoding) must be a valid encoding, "%s" given',
                    $encodingLit
                )
            );
        }
        $mime = MbstringEncodingRegistry::preferredMimeName($canonical);
        if (false === $mime) {
            return $context->constantFromBool(false);
        }

        return self::materializeString($context, $mime);
    }

    /**
     * NestedJIT string (empty = no mime) → `__value__*` string|false.
     */
    private static function boxStringOrEmptyFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_preferred_mime_box');
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $strPtr);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $missBb = BasicBlockHelper::append($context, 'mb_preferred_mime_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_preferred_mime_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_preferred_mime_done');
        $context->builder->branchIf($isEmpty, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
