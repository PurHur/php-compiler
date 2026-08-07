<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin libsodium LLVM for sodium_crypto_generichash() (#27292).
 *
 * NestedJIT of VmSodium/FFI segfaults under thin AOT (handoff #27292; peer AEAD #27318).
 * Emit crypto_generichash against linked libsodium; VM SSOT stays
 * {@see \PHPCompiler\ext\sodium\VmSodium::generichash}.
 * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_crypto_generichash)
 */
final class StringSodiumGenerichash
{
    private const BYTES_MIN = 16;

    private const BYTES_MAX = 64;

    private const KEYBYTES_MIN = 16;

    private const KEYBYTES_MAX = 64;

    private const ABI = '__compiler_sodium_generichash';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        LibcExtern::register($context);
        self::ensureLibsodium($context);
        self::ensureStringAlloc($context);
        self::implement($context);
        self::restoreInsertBlock($context, $restore);
    }

    /** Returns __string__* — BLAKE2b digest (abort on unsupported length/key). */
    public static function invoke(Context $context, Value $message, Value $key, Value $length): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $message,
            $key,
            $length
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('sodium_generichash_entry');
        $context->builder->positionAtEnd($entry);

        $bad = $fn->appendBasicBlock('sodium_generichash_bad');
        $work = $fn->appendBasicBlock('sodium_generichash_work');
        $fail = $fn->appendBasicBlock('sodium_generichash_fail');
        $ok = $fn->appendBasicBlock('sodium_generichash_ok');

        $message = $fn->getParam(0);
        $key = $fn->getParam(1);
        $length = $fn->getParam(2);
        $nullString = $strPtr->constNull();

        $lenOkLo = $context->builder->icmp(
            Builder::INT_SGE,
            $length,
            $i64->constInt(self::BYTES_MIN, true)
        );
        $lenOkHi = $context->builder->icmp(
            Builder::INT_SLE,
            $length,
            $i64->constInt(self::BYTES_MAX, true)
        );
        $keyLen = self::stringLen($context, $key);
        $keyEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $keyLen,
            $i64->constInt(0, false)
        );
        $keyOkLo = $context->builder->icmp(
            Builder::INT_UGE,
            $keyLen,
            $i64->constInt(self::KEYBYTES_MIN, false)
        );
        $keyOkHi = $context->builder->icmp(
            Builder::INT_ULE,
            $keyLen,
            $i64->constInt(self::KEYBYTES_MAX, false)
        );
        $keyOk = $context->builder->or(
            $keyEmpty,
            $context->builder->and($keyOkLo, $keyOkHi)
        );
        $context->builder->branchIf(
            $context->builder->and($context->builder->and($lenOkLo, $lenOkHi), $keyOk),
            $work,
            $bad
        );

        $context->builder->positionAtEnd($bad);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($work);
        $outBuf = $context->builder->call($context->lookupFunction('malloc'), $length);
        $allocFail = $fn->appendBasicBlock('sodium_generichash_alloc_fail');
        $doHash = $fn->appendBasicBlock('sodium_generichash_crypt');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $outBuf, $i8p->constNull()),
            $allocFail,
            $doHash
        );

        $context->builder->positionAtEnd($allocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($doHash);
        $inLen = self::stringLen($context, $message);
        $keyPtr = $context->builder->select(
            $keyEmpty,
            $i8p->constNull(),
            self::stringData($context, $key)
        );
        $rc = $context->builder->call(
            $context->lookupFunction('crypto_generichash'),
            $outBuf,
            $length,
            self::stringData($context, $message),
            $inLen,
            $keyPtr,
            $keyLen
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $ok,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue(self::stringFromBytes($context, $outBuf, $length));
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibsodium(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        // int crypto_generichash(out, outlen, in, inlen, key, keylen)
        self::ensureExternal(
            $context,
            'crypto_generichash',
            $context->context->functionType(
                $i32,
                false,
                $i8p,
                $i64,
                $i8p,
                $i64,
                $i8p,
                $i64
            )
        );
        self::ensureExternal($context, 'abort', $context->context->functionType($voidTy, false));
    }

    private static function ensureStringAlloc(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('__string__init');
        } catch (\Throwable) {
            self::ensureExternal(
                $context,
                '__string__init',
                $context->context->functionType($strPtr, false, $i64, $i8p)
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

    private static function stringLen(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($strObj, $map['length']));
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function stringFromBytes(Context $context, Value $data, Value $len): Value
    {
        return $context->builder->call($context->lookupFunction('__string__init'), $len, $data);
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
