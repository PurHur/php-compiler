<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_getrusage (issue #5388, #3240).
 *
 * Mirrors ext/standard/VmProcess::getrusage() / former phpc_getrusage.c.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 */
final class StringGetrusage
{
    /** Linux x86_64 struct rusage size (sys/resource.h). */
    private const RUSAGE_SIZE = 144;

    /** @var array<string, int> Linux x86_64 field offsets for long members. */
    private const FIELD_OFFSETS = [
        'ru_utime.tv_sec' => 0,
        'ru_utime.tv_usec' => 8,
        'ru_stime.tv_sec' => 16,
        'ru_stime.tv_usec' => 24,
        'ru_maxrss' => 32,
        'ru_ixrss' => 40,
        'ru_idrss' => 48,
        'ru_minflt' => 64,
        'ru_majflt' => 72,
        'ru_nswap' => 80,
        'ru_inblock' => 88,
        'ru_oublock' => 96,
        'ru_msgsnd' => 104,
        'ru_msgrcv' => 112,
        'ru_nsignals' => 120,
        'ru_nvcsw' => 128,
        'ru_nivcsw' => 136,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getrusage');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureLibcGetrusage($context);
        self::ensureHashtableHelpers($context);

        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $i64, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_getrusage', $ft);
        self::implementGetrusage($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementGetrusage(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('gr_entry');
        $context->builder->positionAtEnd($entry);

        $who = $fn->getParam(0);
        $out = $fn->getParam(1);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtrTy->constNull());

        $nullOutBb = $fn->appendBasicBlock('gr_null_out');
        $bodyBb = $fn->appendBasicBlock('gr_body');
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($bodyBb);
        $ru = $context->builder->alloca($i8, self::RUSAGE_SIZE, 'gr_ru');
        $ruPtr = $context->builder->pointerCast($ru, $i8p);
        $oneI64 = $i64->constInt(1, false);
        $negOneI64 = $i64->constInt(-1, false);
        $isChildren = $context->builder->icmp(Builder::INT_EQ, $who, $oneI64);
        $libcWho = $context->builder->select($isChildren, $negOneI64, $who);
        $whoI32 = $context->builder->truncOrBitCast($libcWho, $i32);
        $status = $context->builder->call(
            $context->lookupFunction('getrusage'),
            $whoI32,
            $ruPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $status, $zeroI32);

        $failBb = $fn->appendBasicBlock('gr_fail');
        $buildBb = $fn->appendBasicBlock('gr_build');
        $context->builder->branchIf($ok, $buildBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $zeroI32
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);
        $allocFailBb = $fn->appendBasicBlock('gr_alloc_fail');
        $fillBb = $fn->appendBasicBlock('gr_fill');
        $context->builder->branchIf($htNull, $allocFailBb, $fillBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        foreach (self::FIELD_OFFSETS as $key => $offset) {
            $val = self::loadI64At($context, $ru, $offset);
            $context->builder->call(
                $setLong,
                $ht,
                self::literalString($context, $key),
                $val
            );
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));
        $slot = $context->builder->pointerCast($ptr, $i64->pointerType(0));

        return $context->builder->load($slot);
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureLibcGetrusage(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        self::ensureExternal(
            $context,
            'getrusage',
            $context->context->functionType($i32, false, $i32, $i8p)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_getrusage');
        if (null === $fn) {
            throw new \LogicException('__compiler_getrusage missing after StringGetrusage LLVM implement');
        }
        $context->registerFunction('__compiler_getrusage', $fn);
    }
}
