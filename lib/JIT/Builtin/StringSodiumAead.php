<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin libsodium LLVM for XChaCha20-Poly1305 IETF AEAD (#27318).
 *
 * NestedJIT of FFI ThinAbi segfaults under thin AOT after c:main_before_php
 * (peer zlib #26864 / shmop #28433). Emit crypto_aead_* against linked libsodium;
 * VM SSOT stays {@see \PHPCompiler\ext\sodium\VmSodium}.
 * php-src: ext/sodium/libsodium.c
 */
final class StringSodiumAead
{
    private const KEYBYTES = 32;

    private const NPUBBYTES = 24;

    private const ABYTES = 16;

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_sodium_aead_xchacha_ietf_encrypt',
        '__compiler_sodium_aead_xchacha_ietf_decrypt',
    ];

    private static int $blockSerial = 0;

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_sodium_aead_xchacha_ietf_encrypt');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $restore = self::captureInsertBlock($context);
        LibcExtern::register($context);
        // malloc/free after LibcExtern always-on drop (#32273).
        LibcExtern::ensureMallocFamily($context);
        self::ensureLibsodium($context);
        self::ensureStringAlloc($context);
        self::implementEncrypt($context);
        self::implementDecrypt($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
    }

    /** Returns __value__* — string on success (null ABI → false; peer JitZlib #26864). */
    public static function invokeEncrypt(
        Context $context,
        Value $message,
        Value $additionalData,
        Value $nonce,
        Value $key
    ): Value {
        self::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_sodium_aead_xchacha_ietf_encrypt'),
            $message,
            $additionalData,
            $nonce,
            $key
        );

        return self::stringOrFalse($context, $raw);
    }

    /** Returns __value__* — string on success, bool false on auth failure. */
    public static function invokeDecrypt(
        Context $context,
        Value $ciphertext,
        Value $additionalData,
        Value $nonce,
        Value $key
    ): Value {
        self::ensureLinked($context);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_sodium_aead_xchacha_ietf_decrypt'),
            $ciphertext,
            $additionalData,
            $nonce,
            $key
        );

        return self::stringOrFalse($context, $raw);
    }

    private static function implementEncrypt(Context $context): void
    {
        $abiName = '__compiler_sodium_aead_xchacha_ietf_encrypt';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_aead_enc_entry');
        $context->builder->positionAtEnd($entry);
        $clenOut = $context->builder->alloca($i64);

        $badLen = $fn->appendBasicBlock('sodium_aead_enc_bad_len');
        $work = $fn->appendBasicBlock('sodium_aead_enc_work');
        $fail = $fn->appendBasicBlock('sodium_aead_enc_fail');
        $ok = $fn->appendBasicBlock('sodium_aead_enc_ok');

        $message = $fn->getParam(0);
        $ad = $fn->getParam(1);
        $nonce = $fn->getParam(2);
        $key = $fn->getParam(3);
        $nullString = $strPtr->constNull();

        $nonceOk = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $nonce),
            $i64->constInt(self::NPUBBYTES, false)
        );
        $keyOk = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $key),
            $i64->constInt(self::KEYBYTES, false)
        );
        $context->builder->branchIf($context->builder->and($nonceOk, $keyOk), $work, $badLen);

        $context->builder->positionAtEnd($badLen);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($work);
        $mlen = self::stringLen($context, $message);
        $adlen = self::stringLen($context, $ad);
        $clen = $context->builder->add($mlen, $i64->constInt(self::ABYTES, false));
        $cBuf = $context->builder->call($context->lookupFunction('malloc'), $clen);
        $allocFail = $fn->appendBasicBlock('sodium_aead_enc_alloc_fail');
        $doCrypt = $fn->appendBasicBlock('sodium_aead_enc_crypt');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $cBuf, $i8p->constNull()),
            $allocFail,
            $doCrypt
        );

        $context->builder->positionAtEnd($allocFail);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($doCrypt);
        $context->builder->store($clen, $clenOut);
        $rc = $context->builder->call(
            $context->lookupFunction('crypto_aead_xchacha20poly1305_ietf_encrypt'),
            $cBuf,
            $context->builder->pointerCast($clenOut, $i64p),
            self::stringData($context, $message),
            $mlen,
            self::stringData($context, $ad),
            $adlen,
            $i8p->constNull(),
            self::stringData($context, $nonce),
            self::stringData($context, $key)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $ok,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $cBuf);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($ok);
        $outLen = $context->builder->load($clenOut);
        $context->builder->returnValue(self::stringFromBytes($context, $cBuf, $outLen));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementDecrypt(Context $context): void
    {
        $abiName = '__compiler_sodium_aead_xchacha_ietf_decrypt';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_aead_dec_entry');
        $context->builder->positionAtEnd($entry);
        $mlenOut = $context->builder->alloca($i64);

        $badLen = $fn->appendBasicBlock('sodium_aead_dec_bad_len');
        $shortCt = $fn->appendBasicBlock('sodium_aead_dec_short');
        $work = $fn->appendBasicBlock('sodium_aead_dec_work');
        $fail = $fn->appendBasicBlock('sodium_aead_dec_fail');
        $ok = $fn->appendBasicBlock('sodium_aead_dec_ok');

        $ciphertext = $fn->getParam(0);
        $ad = $fn->getParam(1);
        $nonce = $fn->getParam(2);
        $key = $fn->getParam(3);
        $nullString = $strPtr->constNull();

        $nonceOk = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $nonce),
            $i64->constInt(self::NPUBBYTES, false)
        );
        $keyOk = $context->builder->icmp(
            Builder::INT_EQ,
            self::stringLen($context, $key),
            $i64->constInt(self::KEYBYTES, false)
        );
        $context->builder->branchIf($context->builder->and($nonceOk, $keyOk), $work, $badLen);

        $context->builder->positionAtEnd($badLen);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($work);
        $clen = self::stringLen($context, $ciphertext);
        $afterShort = $fn->appendBasicBlock('sodium_aead_dec_after_short');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGE, $clen, $i64->constInt(self::ABYTES, false)),
            $afterShort,
            $shortCt
        );

        $context->builder->positionAtEnd($shortCt);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($afterShort);
        $mlen = $context->builder->sub($clen, $i64->constInt(self::ABYTES, false));
        $allocLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $mlen, $i64->constInt(0, false)),
            $i64->constInt(1, false),
            $mlen
        );
        $mBuf = $context->builder->call($context->lookupFunction('malloc'), $allocLen);
        $context->builder->store($mlen, $mlenOut);
        $rc = $context->builder->call(
            $context->lookupFunction('crypto_aead_xchacha20poly1305_ietf_decrypt'),
            $mBuf,
            $context->builder->pointerCast($mlenOut, $i64p),
            $i8p->constNull(),
            self::stringData($context, $ciphertext),
            $clen,
            self::stringData($context, $ad),
            self::stringLen($context, $ad),
            self::stringData($context, $nonce),
            self::stringData($context, $key)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $ok,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $mBuf);
        $context->builder->returnValue($nullString);

        $context->builder->positionAtEnd($ok);
        $outLen = $context->builder->load($mlenOut);
        $context->builder->returnValue(self::stringFromBytes($context, $mBuf, $outLen));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibsodium(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $voidTy = $context->getTypeFromString('void');

        self::ensureExternal(
            $context,
            'crypto_aead_xchacha20poly1305_ietf_encrypt',
            $context->context->functionType(
                $i32,
                false,
                $i8p,
                $i64p,
                $i8p,
                $i64,
                $i8p,
                $i64,
                $i8p,
                $i8p,
                $i8p
            )
        );
        self::ensureExternal(
            $context,
            'crypto_aead_xchacha20poly1305_ietf_decrypt',
            $context->context->functionType(
                $i32,
                false,
                $i8p,
                $i64p,
                $i8p,
                $i8p,
                $i64,
                $i8p,
                $i64,
                $i8p,
                $i8p
            )
        );
        self::ensureExternal($context, 'abort', $context->context->functionType($voidTy, false));
    }

    private static function ensureStringAlloc(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        // Prefer existing __string__init from Type\String_; declare only if missing.
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

        // Peer StringZlibJit — structGep of int8 value field is already i8*.
        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function stringFromBytes(Context $context, Value $data, Value $len): Value
    {
        // Peer StringZlibJit — __string__init owns the buffer (frees $data).
        return $context->builder->call($context->lookupFunction('__string__init'), $len, $data);
    }

    private static function stringOrFalse(Context $context, Value $result): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $result, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'sodium_aead_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'sodium_aead_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'sodium_aead_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $result
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringSodiumAead link (#27318)');
            }
            $context->registerFunction($name, $fn);
        }
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
