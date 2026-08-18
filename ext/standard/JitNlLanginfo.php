<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM NestedJIT leaf for nl_langinfo() — thin libc nl_langinfo(3) (#3382, #29459, #30404).
 *
 * Used while NestedJIT compiles {@see NlLanginfoJitHelper} `\nl_langinfo` via
 * {@see \PHPCompiler\JIT\Builtin\StringNlLanginfo} (fnmatch #30383 / time #30332 shape).
 * Invalid items warn then return false (php-src ext/standard/nl_langinfo.c).
 */
final class JitNlLanginfo
{
    /** @return Value `__value__*` — string or bool false */
    public static function invokeLibcLeaf(Context $context, JITVariable $item): Value
    {
        // StringTriggerErrorJit::implement clears insert position after linking bridges —
        // restore so leaf IR stays in the NestedJIT helper body (#30404 / peer #27926).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerErrorJit::implement($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'nl_langinfo_leaf_setup');
        }
        self::ensureSnprintf($context);
        self::ensureLibcNlLanginfo($context);

        $itemVal = self::jitIntArgI32($context, $item);

        $isValid = self::emitIsValidItem($context, $itemVal);
        $invalidBb = BasicBlockHelper::append($context, 'nl_langinfo_invalid');
        $libcBb = BasicBlockHelper::append($context, 'nl_langinfo_libc');
        $doneBb = BasicBlockHelper::append($context, 'nl_langinfo_done');
        $context->builder->branchIf($isValid, $libcBb, $invalidBb);

        $context->builder->positionAtEnd($invalidBb);
        self::emitInvalidItemWarning($context, $itemVal);
        $invalidFalse = self::writeBool($context, false);
        $invalidEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($libcBb);
        $raw = $context->builder->call(
            $context->lookupFunction('nl_langinfo'),
            $itemVal
        );

        $charPtr = $context->getTypeFromString('char*');
        $nullPtr = $context->builder->pointerCast(
            $context->constantFromString(''),
            $charPtr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $nullPtr);
        $emptyBb = BasicBlockHelper::append($context, 'nl_langinfo_false');
        $checkBb = BasicBlockHelper::append($context, 'nl_langinfo_check_empty');
        $emitBb = BasicBlockHelper::append($context, 'nl_langinfo_emit');

        $context->builder->branchIf($isNull, $emptyBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $first = $context->builder->load($raw);
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $first,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $context->builder->branchIf($isEmpty, $emptyBb, $emitBb);

        $context->builder->positionAtEnd($emptyBb);
        // Valid item with empty libc result (e.g. ERA_*) — false without warning (#29459).
        $emptyFalse = self::writeBool($context, false);
        $emptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($emitBb);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $raw
        );
        $i64 = $context->getTypeFromString('int64');
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $raw
        );
        $truePtr = JitValueBox::alloc($context);
        $trueVal = JitValueBox::pointer($context, $truePtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $trueVal,
            $str
        );
        $emitEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($invalidFalse, $invalidEnd);
        $result->addIncoming($emptyFalse, $emptyEnd);
        $result->addIncoming($trueVal, $emitEndBb);

        return $result;
    }

    /** @return Value int64 — zval long for helper ABI */
    public static function jitIntArgI64(Context $context, JITVariable $arg): Value
    {
        return JitSleep::zParamLong($context, $arg, 'nl_langinfo', 1, 'item');
    }

    /** OR of (item == each registered nl_langinfo constant) at emit time. */
    private static function emitIsValidItem(Context $context, Value $itemVal): Value
    {
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $valid = $i1->constInt(0, false);
        foreach (\array_unique(\array_values(VmLocale::nlLanginfoConstants())) as $const) {
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $itemVal,
                $i32->constInt((int) $const, true)
            );
            $valid = $context->builder->or($valid, $eq);
        }

        return $valid;
    }

    private static function emitInvalidItemWarning(Context $context, Value $itemVal): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("nl_langinfo(): Item '%d' is not valid"),
            $i8p
        );
        $msgSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(64));
        $msg = $context->builder->pointerCast($msgSlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $msg,
            $sizeT->constInt(64, false),
            $fmt,
            $itemVal
        );
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $sizeT->constInt(63, false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function ensureLibcNlLanginfo(Context $context): void
    {
        try {
            $context->lookupFunction('nl_langinfo');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'nl_langinfo',
                $context->context->functionType($i8p, false, $i32)
            );
            $context->registerFunction('nl_langinfo', $fn);
        }
    }

    private static function ensureSnprintf(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        try {
            $context->lookupFunction('snprintf');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'snprintf',
                $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
            );
            $context->registerFunction('snprintf', $fn);
        }
    }

    private static function jitIntArgI32(Context $context, JITVariable $arg): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->trunc(self::jitIntArgI64($context, $arg), $i32);
    }

    private static function writeBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt($value ? 1 : 0, false)
        );

        return $ptr;
    }
}
