<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitJsonDecode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbGetInfoRuntime;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
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
 * LLVM JIT/AOT for mb_get_info() (php-src ext/mbstring/mbstring.c; #20014).
 *
 * Compile-time fold when the type is a literal; runtime via NestedJIT
 * {@see MbGetInfoJitHelper} (peer {@see JitMbHttpInput}).
 */
final class JitMbGetInfo
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_get_info() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc) {
            return self::materialize($context, MbstringState::getInfo('all'));
        }

        $typeLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $typeLit) {
            return self::materialize($context, MbstringState::getInfo($typeLit));
        }

        return self::lowerRuntimeType($context, $args[0]);
    }

    private static function lowerRuntimeType(Context $context, JITVariable $arg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbGetInfoRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_get_info_runtime');

        $typeStr = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'mb_get_info',
            0,
            'type'
        );
        $kindRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbGetInfoRuntime::kindHelper($context),
            [$typeStr]
        );
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);
        $i64 = $context->getTypeFromString('int64');
        $kind = JitNestedHelperCoerce::extractLongFromHelperResult($context, $kindRaw, $i64);

        $payloadRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbGetInfoRuntime::payloadHelper($context),
            [$typeStr]
        );
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);
        $payload = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $payloadRaw);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_done');

        $falseBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_false');
        $nullBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_null');
        $intBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_int');
        $stringBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_string');
        $arrayBb = BasicBlockHelper::append($context, 'mb_get_info_runtime_array');

        self::branchKind($context, $kind, $i64, $falseBb, $nullBb, $intBb, $stringBb, $arrayBb, $doneBb);

        $context->builder->positionAtEnd($falseBb);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($intBb);
        $intRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbGetInfoRuntime::intHelper($context),
            [$typeStr]
        );
        TryCatchHelper::emitCheckPendingThrowAfterCall($context);
        $parsed = JitNestedHelperCoerce::extractLongFromHelperResult($context, $intRaw, $i64);
        JitValueBox::writeLong($context, $slot, $parsed);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $payload);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($arrayBb);
        StringJsonDecode::ensureLinked($context);
        $decoded = JitJsonDecode::decodeRuntimeString($context, $payload);
        JitValueBox::copyFromPointer($context, $slot, $decoded);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function branchKind(
        Context $context,
        Value $kind,
        $i64,
        $falseBb,
        $nullBb,
        $intBb,
        $stringBb,
        $arrayBb,
        $doneBb
    ): void {
        $signed = true;
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbGetInfoJitHelper::KIND_FALSE, $signed)
        );
        $notFalseBb = BasicBlockHelper::append($context, 'mb_get_info_not_false');
        $context->builder->branchIf($isFalse, $falseBb, $notFalseBb);

        $context->builder->positionAtEnd($notFalseBb);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbGetInfoJitHelper::KIND_NULL, $signed)
        );
        $notNullBb = BasicBlockHelper::append($context, 'mb_get_info_not_null');
        $context->builder->branchIf($isNull, $nullBb, $notNullBb);

        $context->builder->positionAtEnd($notNullBb);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbGetInfoJitHelper::KIND_INT, $signed)
        );
        $notIntBb = BasicBlockHelper::append($context, 'mb_get_info_not_int');
        $context->builder->branchIf($isInt, $intBb, $notIntBb);

        $context->builder->positionAtEnd($notIntBb);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbGetInfoJitHelper::KIND_STRING, $signed)
        );
        $notStringBb = BasicBlockHelper::append($context, 'mb_get_info_not_string');
        $context->builder->branchIf($isString, $stringBb, $notStringBb);

        $context->builder->positionAtEnd($notStringBb);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbGetInfoJitHelper::KIND_ARRAY, $signed)
        );
        $context->builder->branchIf($isArray, $arrayBb, $falseBb);
    }

    /**
     * @param array<string, mixed>|string|int|false|null $result
     */
    private static function materialize(Context $context, array|string|int|false|null $result): Value
    {
        if (null === $result) {
            return JitJsonDecode::materializeNull($context);
        }
        if (false === $result) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        if (\is_int($result)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong(
                $context,
                $slot,
                $context->getTypeFromString('int64')->constInt($result, true)
            );

            return JitValueBox::pointer($context, $slot);
        }
        if (\is_string($result)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($result))
            );
            $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

            return $ptr;
        }

        return JitJsonDecode::materializeArray($context, $result);
    }
}
