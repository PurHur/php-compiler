<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for phpc_argon2_hash / phpc_argon2_verify — thin libargon2 (#26773).
 *
 * NestedJIT leaf inside {@see PasswordJitHelper}. Avoids FFI (unusable under AOT standalone).
 * php-src: ext/standard/password.c — php_password_argon2_* / argon2_hash / argon2_verify
 */
final class JitArgon2Kernel
{
    private const HASH_LEN = 32;

    private const VERSION = 19;

    /**
     * @return Value `__string__*` — null pointer when hash fails
     *
     * Salt is raw bytes (php-src password.c uses 16-byte salt).
     */
    public static function hash(
        Context $context,
        Value $passwordStr,
        Value $typeI64,
        Value $memoryI64,
        Value $timeI64,
        Value $threadsI64,
        Value $saltStr
    ): Value {
        LibcExtern::register($context);
        // malloc/free after LibcExtern always-on drop (#32273).
        LibcExtern::ensureMallocFamily($context);
        self::registerArgon2Decls($context);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $nullOut = $strPtrTy->constNull();
        $tag = 'argon2_h_'.bin2hex(\random_bytes(3));

        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $allocBlock = BasicBlockHelper::append($context, $tag.'_alloc');
        $callBlock = BasicBlockHelper::append($context, $tag.'_call');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $badStatusBlock = BasicBlockHelper::append($context, $tag.'_bad_status');
        $mergeBlock = BasicBlockHelper::append($context, $tag.'_merge');

        $pwdCstr = self::stringDataPtr($context, $passwordStr);
        $saltCstr = self::stringDataPtr($context, $saltStr);
        $pwdLen = $context->builder->call($context->lookupFunction('__string__strlen'), $passwordStr);
        $saltLen = $context->builder->call($context->lookupFunction('__string__strlen'), $saltStr);

        $tCost = $context->builder->trunc($timeI64, $i32);
        $mCost = $context->builder->trunc($memoryI64, $i32);
        $threads = $context->builder->trunc($threadsI64, $i32);
        $type = $context->builder->trunc($typeI64, $i32);
        $saltLenI32 = $context->builder->trunc($saltLen, $i32);
        $hashLenI32 = $i32->constInt(self::HASH_LEN, false);

        $encodedLenSize = $context->builder->call(
            self::lookup($context, 'argon2_encodedlen'),
            $tCost,
            $mCost,
            $threads,
            $saltLenI32,
            $hashLenI32,
            $type
        );
        $encodedLen = $encodedLenSize->typeOf() === $i64
            ? $encodedLenSize
            : $context->builder->zExt($encodedLenSize, $i64);
        $encTooSmall = $context->builder->icmp(
            Builder::INT_SLE,
            $encodedLen,
            $i64->constInt(1, false)
        );
        $context->builder->branchIf($encTooSmall, $failBlock, $allocBlock);

        $context->builder->positionAtEnd($allocBlock);
        $hashBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $i64->constInt(self::HASH_LEN, false)
        );
        $encodedBuf = $context->builder->call($context->lookupFunction('malloc'), $encodedLen);
        $hashNull = $context->builder->icmp(Builder::INT_EQ, $hashBuf, $i8p->constNull());
        $encNull = $context->builder->icmp(Builder::INT_EQ, $encodedBuf, $i8p->constNull());
        $allocFail = $context->builder->or($hashNull, $encNull);
        $context->builder->branchIf($allocFail, $badStatusBlock, $callBlock);

        $context->builder->positionAtEnd($callBlock);
        $status = $context->builder->call(
            self::lookup($context, 'argon2_hash'),
            $tCost,
            $mCost,
            $threads,
            $pwdCstr,
            $pwdLen,
            $saltCstr,
            $saltLen,
            $hashBuf,
            $i64->constInt(self::HASH_LEN, false),
            $encodedBuf,
            $encodedLen,
            $type,
            $i32->constInt(self::VERSION, false)
        );
        $statusI32 = $status->typeOf() === $i32
            ? $status
            : $context->builder->trunc($status, $i32);
        $hashOk = $context->builder->icmp(
            Builder::INT_EQ,
            $statusI32,
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($hashOk, $okBlock, $badStatusBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('free'), $hashBuf);
        $encodedBodyLen = $context->builder->sub($encodedLen, $i64->constInt(1, false));
        $out = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $encodedBodyLen,
            $encodedBuf
        );
        $context->builder->call($context->lookupFunction('free'), $encodedBuf);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($badStatusBlock);
        $context->builder->call($context->lookupFunction('free'), $hashBuf);
        $context->builder->call($context->lookupFunction('free'), $encodedBuf);
        $badEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($strPtrTy);
        $phi->addIncoming($out, $okEnd);
        $phi->addIncoming($nullOut, $badEnd);
        $phi->addIncoming($nullOut, $failEnd);

        return $phi;
    }

    /** @return Value i64 — 1 on match, 0 otherwise */
    public static function verify(Context $context, Value $passwordStr, Value $hashStr, Value $typeI64): Value
    {
        LibcExtern::register($context);
        // malloc/free after LibcExtern always-on drop (#32273).
        LibcExtern::ensureMallocFamily($context);
        self::registerArgon2Decls($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $pwdCstr = self::stringDataPtr($context, $passwordStr);
        $hashCstr = self::stringDataPtr($context, $hashStr);
        $pwdLen = $context->builder->call($context->lookupFunction('__string__strlen'), $passwordStr);
        $type = $context->builder->trunc($typeI64, $i32);
        $status = $context->builder->call(
            self::lookup($context, 'argon2_verify'),
            $hashCstr,
            $pwdCstr,
            $pwdLen,
            $type
        );
        $statusI32 = $status->typeOf() === $i32
            ? $status
            : $context->builder->trunc($status, $i32);
        $eq = $context->builder->icmp(
            Builder::INT_EQ,
            $statusI32,
            $i32->constInt(0, false)
        );

        return $context->builder->select(
            $eq,
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function lookup(Context $context, string $name): LlvmFunction
    {
        return $context->lookupFunction($name);
    }

    private static function registerArgon2Decls(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $i64;

        try {
            $context->lookupFunction('argon2_encodedlen');
        } catch (\Throwable) {
            $ft = $context->context->functionType(
                $sizeT,
                false,
                $i32,
                $i32,
                $i32,
                $i32,
                $i32,
                $i32
            );
            $fn = $context->module->addFunction('argon2_encodedlen', $ft);
            $context->registerFunction('argon2_encodedlen', $fn);
        }
        try {
            $context->lookupFunction('argon2_hash');
        } catch (\Throwable) {
            $ft = $context->context->functionType(
                $i32,
                false,
                $i32,
                $i32,
                $i32,
                $i8p,
                $sizeT,
                $i8p,
                $sizeT,
                $i8p,
                $sizeT,
                $i8p,
                $sizeT,
                $i32,
                $i32
            );
            $fn = $context->module->addFunction('argon2_hash', $ft);
            $context->registerFunction('argon2_hash', $fn);
        }
        try {
            $context->lookupFunction('argon2_verify');
        } catch (\Throwable) {
            $ft = $context->context->functionType(
                $i32,
                false,
                $i8p,
                $i8p,
                $sizeT,
                $i32
            );
            $fn = $context->module->addFunction('argon2_verify', $ft);
            $context->registerFunction('argon2_verify', $fn);
        }
    }
}
