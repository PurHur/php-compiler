<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for strcoll via libc trampoline (#13566, #27059, #31498).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\StrcollJitHelper} mis-reads {@see __string__*}
 * under thin AOT (silent 0 — peer {@see StringStrspn} / #27051 / #27053). php-src calls
 * libc strcoll(3) on C strings; keep that on the i8* ABI and avoid NestedJIT strlen.
 *
 * PHP bridge uses `__compiler_strcoll` so AOT does not export libc `strcoll` (#26861).
 * Libc `strcoll(3)` is declared module-locally (LibcExtern always-on drop #31498 / peer #31458).
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmLocaleCollate}.
 */
final class StringStrcoll
{
    public const ABI_STRCOLL = '__compiler_strcoll';

    public static function ensureLinked(Context $context): void
    {
        self::implementNamed($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implementNamed(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_STRCOLL);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_STRCOLL, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureLibcStrcoll($context);
        self::implementLibcTrampoline($context, $probe);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Module-local strcoll(3) after LibcExtern always-on drop (#31498).
     */
    private static function ensureLibcStrcoll(Context $context): void
    {
        try {
            $context->lookupFunction('strcoll');
        } catch (\Throwable) {
            $i8p = $context->getTypeFromString('int8*');
            $i32 = $context->getTypeFromString('int32');
            $fn = $context->module->addFunction(
                'strcoll',
                $context->context->functionType($i32, false, $i8p, $i8p)
            );
            $context->registerFunction('strcoll', $fn);
        }
    }

    private static function implementLibcTrampoline(Context $context, ?LlvmFunction $probe): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_STRCOLL, $ft);

        $entry = $fn->appendBasicBlock('strcoll_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $a = $fn->getParam(0);
        $b = $fn->getParam(1);
        $null = $i8p->constNull();

        // libc strcoll(NULL, …) is UB — treat null like empty string (php-src Z_STRVAL).
        $emptySlot = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $aIsNull = $context->builder->icmp(Builder::INT_EQ, $a, $null);
        $bIsNull = $context->builder->icmp(Builder::INT_EQ, $b, $null);
        $aPtr = $context->builder->select($aIsNull, $emptyPtr, $a);
        $bPtr = $context->builder->select($bIsNull, $emptyPtr, $b);

        $raw = $context->builder->call($context->lookupFunction('strcoll'), $aPtr, $bPtr);
        $context->builder->returnValue($raw);
        $context->registerFunction(self::ABI_STRCOLL, $fn);
    }
}
