<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin LLVM SSOT for compiled-module INI keys NestedJIT cannot store (#33059 / #11841).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\IniJitHelper} class statics split across
 * addresses under thin AOT (reset writes ≠ getter reads); const-array isset() is also
 * BSS-toxic and returns formatBoolIniGet(false)="0" for every key. Precision /
 * serialize_precision / memory_limit live in module globals — peer
 * {@see OutputRewriteVarsStorage} / exception_ignore_args thin path (#27549).
 *
 * php-src: ext/standard/ini.c — PG(precision), PG(serialize_precision), PG(memory_limit)
 */
final class IniRuntimeThinStorage
{
    public const G_PRECISION = 'phpc_ini_precision';

    public const G_SERIALIZE_PRECISION = 'phpc_ini_serialize_precision';

    public const G_MEMORY_LIMIT_BUF = 'phpc_ini_memory_limit_buf';

    public const G_MEMORY_LIMIT_LEN = 'phpc_ini_memory_limit_len';

    private const PRECISION_KEY = 'precision';

    private const SERIALIZE_PRECISION_KEY = 'serialize_precision';

    private const MEMORY_LIMIT_KEY = 'memory_limit';

    private const DEFAULT_PRECISION = 14;

    private const DEFAULT_SERIALIZE_PRECISION = -1;

    private const DEFAULT_MEMORY_LIMIT = '-1';

    private const MEMORY_LIMIT_CAP = 63;

    public static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');

        if (null === $context->module->getNamedGlobal(self::G_SERIALIZE_PRECISION)) {
            $g = $context->module->addGlobal($i32, self::G_SERIALIZE_PRECISION);
            $g->setInitializer($i32->constInt(self::DEFAULT_SERIALIZE_PRECISION, true));
        }
        if (null === $context->module->getNamedGlobal(self::G_PRECISION)) {
            $g = $context->module->addGlobal($i32, self::G_PRECISION);
            $g->setInitializer($i32->constInt(self::DEFAULT_PRECISION, true));
        }
        // len==0 ⇒ compiled default "-1" (avoid constArray init; peer SessionModuleName).
        if (null === $context->module->getNamedGlobal(self::G_MEMORY_LIMIT_BUF)) {
            $arrTy = $i8->arrayType(self::MEMORY_LIMIT_CAP + 1);
            $g = $context->module->addGlobal($arrTy, self::G_MEMORY_LIMIT_BUF);
            $g->setInitializer($arrTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_MEMORY_LIMIT_LEN)) {
            $g = $context->module->addGlobal($i64, self::G_MEMORY_LIMIT_LEN);
            $g->setInitializer($i64->constInt(0, false));
        }
    }

    public static function emitOptionIsPrecision(Context $context, Value $optionStr): Value
    {
        return self::emitOptionEquals($context, $optionStr, self::PRECISION_KEY);
    }

    public static function emitOptionIsSerializePrecision(Context $context, Value $optionStr): Value
    {
        return self::emitOptionEquals($context, $optionStr, self::SERIALIZE_PRECISION_KEY);
    }

    public static function emitOptionIsMemoryLimit(Context $context, Value $optionStr): Value
    {
        return self::emitOptionEquals($context, $optionStr, self::MEMORY_LIMIT_KEY);
    }

    /**
     * Keys that may NestedJIT (non-thin). Unknown keys must not call NestedJIT — nullable
     * returns TypeError-abort under thin AOT (#33059).
     */
    public static function emitOptionIsKnownNestedKey(Context $context, Value $optionStr): Value
    {
        $keys = [
            'error_reporting',
            'display_errors',
            'include_path',
            'open_basedir',
            'default_charset',
            'date.timezone',
            'user_agent',
            'url_rewriter.tags',
            'url_rewriter.hosts',
            'pcre.backtrack_limit',
            'pcre.jit',
            'pcre.recursion_limit',
            'zend.exception_string_param_max_len',
            'max_execution_time',
            'register_argc_argv',
            'unserialize_max_depth',
            'unserialize_callback_func',
            'session.gc_maxlifetime',
            'session.save_path',
            'session.use_strict_mode',
            'enable_dl',
            'short_open_tag',
            'zend.enable_gc',
            'extension_dir',
            'sendmail_path',
            'user_ini.filename',
            'variables_order',
            'request_order',
        ];
        $acc = null;
        foreach ($keys as $key) {
            $eq = self::emitOptionEquals($context, $optionStr, $key);
            $acc = null === $acc ? $eq : $context->builder->or($acc, $eq);
        }

        return $acc;
    }

    public static function emitThinGetPrecision(Context $context, Value $out): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $val = $context->builder->load(self::globalPtr($context, self::G_PRECISION, $i32));
        self::emitWriteInt32AsIniString($context, $val, $out);
    }

    public static function emitThinGetSerializePrecision(Context $context, Value $out): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $val = $context->builder->load(self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32));
        self::emitWriteInt32AsIniString($context, $val, $out);
    }

    public static function emitThinGetMemoryLimit(Context $context, Value $out): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->load(self::globalPtr($context, self::G_MEMORY_LIMIT_LEN, $i64));
        $isDefault = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $defBb = BasicBlockHelper::append($context, 'ini_mem_get_default');
        $bufBb = BasicBlockHelper::append($context, 'ini_mem_get_buf');
        $doneBb = BasicBlockHelper::append($context, 'ini_mem_get_done');
        $context->builder->branchIf($isDefault, $defBb, $bufBb);

        $context->builder->positionAtEnd($defBb);
        $lit = $context->builder->load($context->constantStringFromString(self::DEFAULT_MEMORY_LIMIT));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $lit);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bufBb);
        self::emitWriteMemoryLimitBufAsString($context, $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    public static function emitThinSetPrecision(Context $context, Value $valueStr, Value $out): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $gPtr = self::globalPtr($context, self::G_PRECISION, $i32);
        $old = $context->builder->load($gPtr);
        self::emitWriteInt32AsIniString($context, $old, $out);
        $parsed = self::emitParseInt32($context, $valueStr);
        $context->builder->store($parsed, $gPtr);
    }

    public static function emitThinSetSerializePrecision(Context $context, Value $valueStr, Value $out): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $gPtr = self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32);
        $old = $context->builder->load($gPtr);
        self::emitWriteInt32AsIniString($context, $old, $out);
        $parsed = self::emitParseSerializePrecision($context, $valueStr);
        $context->builder->store($parsed, $gPtr);
    }

    public static function emitThinSetMemoryLimit(Context $context, Value $valueStr, Value $out): void
    {
        self::ensureGlobals($context);
        // Return previous value (default or buf) before overwrite.
        self::emitThinGetMemoryLimit($context, $out);
        self::emitStoreMemoryLimitFromString($context, $valueStr);
    }

    public static function emitThinRestorePrecision(Context $context): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store(
            $i32->constInt(self::DEFAULT_PRECISION, true),
            self::globalPtr($context, self::G_PRECISION, $i32)
        );
    }

    public static function emitThinRestoreSerializePrecision(Context $context): void
    {
        self::ensureGlobals($context);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store(
            $i32->constInt(self::DEFAULT_SERIALIZE_PRECISION, true),
            self::globalPtr($context, self::G_SERIALIZE_PRECISION, $i32)
        );
    }

    public static function emitThinRestoreMemoryLimit(Context $context): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i64->constInt(0, false),
            self::globalPtr($context, self::G_MEMORY_LIMIT_LEN, $i64)
        );
    }

    private static function emitOptionEquals(Context $context, Value $optionStr, string $key): Value
    {
        LibcExtern::ensureStrcmpDecl($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $strMap = $context->structFieldMap['__string__'];
        $optCstr = $context->builder->pointerCast(
            $context->builder->structGep($optionStr, $strMap['value']),
            $i8p
        );
        // libc strcmp — __compiler_strcasecmp NestedJIT always-eq under thin AOT made every
        // key hit zend.exception_ignore_args → string "0" (#33059). Keys are lowercase ASCII.
        $wantCstr = $context->pointerFromStringConstant($key);
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $optCstr,
            $wantCstr
        );

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
    }

    private static function emitWriteInt32AsIniString(Context $context, Value $valI32, Value $out): void
    {
        // Prefer constant literals for the compiled defaults — avoids snprintf ABI issues
        // and matches Zend's ini_get string form for PG(precision)=14 / serialize_precision=-1.
        $i32 = $context->getTypeFromString('int32');
        $is14 = $context->builder->icmp(
            Builder::INT_EQ,
            $valI32,
            $i32->constInt(14, true)
        );
        $isNeg1 = $context->builder->icmp(
            Builder::INT_EQ,
            $valI32,
            $i32->constInt(-1, true)
        );
        $is0 = $context->builder->icmp(
            Builder::INT_EQ,
            $valI32,
            $i32->constInt(0, true)
        );
        $lit14Bb = BasicBlockHelper::append($context, 'ini_int_lit_14');
        $litNeg1Bb = BasicBlockHelper::append($context, 'ini_int_lit_neg1');
        $lit0Bb = BasicBlockHelper::append($context, 'ini_int_lit_0');
        $dynBb = BasicBlockHelper::append($context, 'ini_int_dyn');
        $doneBb = BasicBlockHelper::append($context, 'ini_int_done');
        $after14 = BasicBlockHelper::append($context, 'ini_int_after_14');
        $context->builder->branchIf($is14, $lit14Bb, $after14);

        $context->builder->positionAtEnd($lit14Bb);
        $s14 = $context->builder->load($context->constantStringFromString('14'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $s14);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($after14);
        $afterNeg1 = BasicBlockHelper::append($context, 'ini_int_after_neg1');
        $context->builder->branchIf($isNeg1, $litNeg1Bb, $afterNeg1);

        $context->builder->positionAtEnd($litNeg1Bb);
        $sNeg1 = $context->builder->load($context->constantStringFromString('-1'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $sNeg1);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterNeg1);
        $context->builder->branchIf($is0, $lit0Bb, $dynBb);

        $context->builder->positionAtEnd($lit0Bb);
        $s0 = $context->builder->load($context->constantStringFromString('0'));
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $s0);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dynBb);
        LibcExtern::ensureSnprintf($context);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = $context->builder->alloca($i8->arrayType(32), 1, 'ini_int_buf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmt = $context->pointerFromStringConstant('%d');
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $context->constantFromInteger(32, 'size_t'),
            $fmt,
            $valI32
        );
        $len = $context->builder->zext(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SLT, $written, $i32->constInt(0, true)),
                $i32->constInt(0, false),
                $written
            ),
            $sizeT
        );
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $strMap = $context->structFieldMap['__string__'];
        $dst = $context->builder->pointerCast(
            $context->builder->structGep($str, $strMap['value']),
            $i8p
        );
        LibcExtern::ensureMemcpyDecl($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dst,
            $bufPtr,
            $len
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitParseInt32(Context $context, Value $valueStr): Value
    {
        LibcExtern::ensureStrtolDecl($context);
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i32 = $context->getTypeFromString('int32');
        $strMap = $context->structFieldMap['__string__'];
        $cstr = $context->builder->pointerCast(
            $context->builder->structGep($valueStr, $strMap['value']),
            $i8p
        );
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $cstr,
            $i8pp->constNull(),
            $i32->constInt(10, false)
        );

        return $context->builder->trunc($parsed, $i32);
    }

    /** Empty string → -1 (VmIni / IniJitHelper::parseSerializePrecisionIni). */
    private static function emitParseSerializePrecision(Context $context, Value $valueStr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strMap = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($valueStr, $strMap['length']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $parsed = self::emitParseInt32($context, $valueStr);

        return $context->builder->select($empty, $i32->constInt(-1, true), $parsed);
    }

    private static function emitWriteMemoryLimitBufAsString(Context $context, Value $out): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->load(self::globalPtr($context, self::G_MEMORY_LIMIT_LEN, $i64));
        $str = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->builder->intCast($len, $sizeT)
        );
        $strMap = $context->structFieldMap['__string__'];
        $dst = $context->builder->pointerCast(
            $context->builder->structGep($str, $strMap['value']),
            $i8p
        );
        $src = $context->builder->inBoundsGEP(
            $context->module->getNamedGlobal(self::G_MEMORY_LIMIT_BUF),
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        LibcExtern::ensureMemcpyDecl($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dst,
            $context->builder->pointerCast($src, $i8p),
            $context->builder->intCast($len, $sizeT)
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $str);
    }

    private static function emitStoreMemoryLimitFromString(Context $context, Value $valueStr): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $srcLen = $context->builder->load($context->builder->structGep($valueStr, $strMap['length']));
        $cap = $i64->constInt(self::MEMORY_LIMIT_CAP, false);
        $useLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $srcLen, $cap),
            $srcLen,
            $cap
        );
        $context->builder->store($useLen, self::globalPtr($context, self::G_MEMORY_LIMIT_LEN, $i64));
        $dst = $context->builder->inBoundsGEP(
            $context->module->getNamedGlobal(self::G_MEMORY_LIMIT_BUF),
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $src = $context->builder->pointerCast(
            $context->builder->structGep($valueStr, $strMap['value']),
            $i8p
        );
        LibcExtern::ensureMemcpyDecl($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dst, $i8p),
            $src,
            $context->builder->intCast($useLen, $sizeT)
        );
        $nul = $context->builder->inBoundsGEP($dst, $useLen);
        $context->builder->store($i8->constInt(0, false), $nul);
    }

    private static function globalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('IniRuntimeThinStorage global missing: '.$name);
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }
}
