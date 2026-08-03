<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * libcrypto EVP cipher NestedJIT leaf for OpensslEncryptJitHelper (#27265).
 *
 * Thin-standalone AOT has no FFI ({@see extension_loaded}('ffi') → false), so the NestedJIT
 * helper cannot call {@see VmOpensslCipherNative}. Same shape as {@see \PHPCompiler\ext\hash\JitHashCryptoKernel}.
 * php-src: ext/openssl/openssl.c — php_openssl_encrypt / php_openssl_decrypt
 */
final class JitOpensslCipherKernel
{
    public const EVP_ENCRYPT = '__phpc_ossl_evp_encrypt';

    public const EVP_DECRYPT = '__phpc_ossl_evp_decrypt';

    /** Extra bytes beyond plaintext for PKCS#7 padding / AEAD expansion. */
    private const OUT_PAD = 64;

    public static function available(): bool
    {
        return VmOpensslSignNative::available();
    }

    public static function ensureEvpLeaves(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (!self::available()) {
            self::implementNullStubs($context);

            return;
        }

        LibcExtern::register($context);
        self::ensureLibcrypto($context);

        self::implementIfMissing($context, self::EVP_ENCRYPT, 'ossl_llvm_encrypt_entry', self::emitCrypt(...), true);
        self::implementIfMissing($context, self::EVP_DECRYPT, 'ossl_llvm_decrypt_entry', self::emitCrypt(...), false);
    }

    /**
     * @param callable(Context, LlvmFunction, bool): void $emit
     */
    private static function implementIfMissing(
        Context $context,
        string $abiName,
        string $entryName,
        callable $emit,
        bool $encrypt
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $fn = null !== $probe ? $probe : self::declareLeaf($context, $abiName);
        $emit($context, $fn, $encrypt);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareLeaf(Context $context, string $abiName): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        // data, cipher, key, iv, zero_padding, raw_output (1=raw, 0=base64)
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $strPtr, $i32, $i32);

        return $context->module->addFunction($abiName, $ft);
    }

    private static function implementNullStubs(Context $context): void
    {
        foreach ([self::EVP_ENCRYPT => 'ossl_llvm_encrypt_stub', self::EVP_DECRYPT => 'ossl_llvm_decrypt_stub'] as $abi => $entry) {
            $probe = $context->module->getNamedFunction($abi);
            if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
                $context->registerFunction($abi, $probe);

                continue;
            }
            $fn = null !== $probe ? $probe : self::declareLeaf($context, $abi);
            $block = $fn->appendBasicBlock($entry);
            $context->builder->positionAtEnd($block);
            $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
            $context->registerFunction($abi, $fn);
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitCrypt(Context $context, LlvmFunction $fn, bool $encrypt): void
    {
        $entryName = $encrypt ? 'ossl_llvm_encrypt_entry' : 'ossl_llvm_decrypt_entry';
        $prefix = $encrypt ? 'ossl_enc' : 'ossl_dec';
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $cipherAlgo = $fn->getParam(1);
        $key = $fn->getParam(2);
        $iv = $fn->getParam(3);
        $zeroPadding = $fn->getParam(4);
        $rawOutput = $fn->getParam(5);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $algoCstr = self::stringToCstr($context, $cipherAlgo);
        $cipher = $context->builder->call(
            $context->lookupFunction('EVP_get_cipherbyname'),
            $algoCstr
        );
        $failAlgo = $fn->appendBasicBlock($prefix.'_fail_algo');
        $haveCipher = $fn->appendBasicBlock($prefix.'_have_cipher');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $cipher, $i8p->constNull()),
            $failAlgo,
            $haveCipher
        );

        $context->builder->positionAtEnd($failAlgo);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveCipher);
        $ctx = $context->builder->call($context->lookupFunction('EVP_CIPHER_CTX_new'));
        $failCtx = $fn->appendBasicBlock($prefix.'_fail_ctx');
        $haveCtx = $fn->appendBasicBlock($prefix.'_have_ctx');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ctx, $i8p->constNull()),
            $failCtx,
            $haveCtx
        );

        $context->builder->positionAtEnd($failCtx);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveCtx);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);

        $keyPtr = self::stringData($context, $key);
        $ivPtr = self::stringData($context, $iv);
        $nullImpl = $i8p->constNull();

        $initFn = $encrypt ? 'EVP_EncryptInit_ex' : 'EVP_DecryptInit_ex';
        $updateFn = $encrypt ? 'EVP_EncryptUpdate' : 'EVP_DecryptUpdate';
        $finalFn = $encrypt ? 'EVP_EncryptFinal_ex' : 'EVP_DecryptFinal_ex';

        $okInit = $context->builder->call(
            $context->lookupFunction($initFn),
            $ctx,
            $cipher,
            $nullImpl,
            $keyPtr,
            $ivPtr
        );
        $failInit = $fn->appendBasicBlock($prefix.'_fail_init');
        $afterInit = $fn->appendBasicBlock($prefix.'_after_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okInit, $i32->constInt(1, false)),
            $failInit,
            $afterInit
        );

        $context->builder->positionAtEnd($failInit);
        $context->builder->call($context->lookupFunction('EVP_CIPHER_CTX_free'), $ctx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterInit);
        $wantZero = $context->builder->icmp(Builder::INT_NE, $zeroPadding, $i32->constInt(0, false));
        $padBb = $fn->appendBasicBlock($prefix.'_set_pad');
        $afterPad = $fn->appendBasicBlock($prefix.'_after_pad');
        $context->builder->branchIf($wantZero, $padBb, $afterPad);

        $context->builder->positionAtEnd($padBb);
        $context->builder->call(
            $context->lookupFunction('EVP_CIPHER_CTX_set_padding'),
            $ctx,
            $i32->constInt(0, false)
        );
        $context->builder->branch($afterPad);

        $context->builder->positionAtEnd($afterPad);
        $dataLenI64 = self::stringLenI64($context, $data);
        $outCap = $context->builder->add($dataLenI64, $i64->constInt(self::OUT_PAD, false));
        $outCapSize = $context->builder->truncOrBitCast($outCap, $sizeT);
        $outBuf = $context->builder->call($context->lookupFunction('malloc'), $outCapSize);
        $failMalloc = $fn->appendBasicBlock($prefix.'_fail_malloc');
        $haveOut = $fn->appendBasicBlock($prefix.'_have_out');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $outBuf, $i8p->constNull()),
            $failMalloc,
            $haveOut
        );

        $context->builder->positionAtEnd($failMalloc);
        $context->builder->call($context->lookupFunction('EVP_CIPHER_CTX_free'), $ctx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveOut);
        $len1Slot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $len1Slot);
        $dataPtr = self::stringData($context, $data);
        $dataLenI32 = $context->builder->truncOrBitCast($dataLenI64, $i32);

        $okUpdate = $context->builder->call(
            $context->lookupFunction($updateFn),
            $ctx,
            $outBuf,
            $len1Slot,
            $dataPtr,
            $dataLenI32
        );
        $failUpdate = $fn->appendBasicBlock($prefix.'_fail_update');
        $afterUpdate = $fn->appendBasicBlock($prefix.'_after_update');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okUpdate, $i32->constInt(1, false)),
            $failUpdate,
            $afterUpdate
        );

        $context->builder->positionAtEnd($failUpdate);
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->call($context->lookupFunction('EVP_CIPHER_CTX_free'), $ctx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterUpdate);
        $len1 = $context->builder->load($len1Slot);
        $len1I64 = $context->builder->zExt($len1, $i64);
        $finalPtr = $context->builder->inBoundsGEP($outBuf, $len1I64);
        $len2Slot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $len2Slot);
        $okFinal = $context->builder->call(
            $context->lookupFunction($finalFn),
            $ctx,
            $finalPtr,
            $len2Slot
        );
        $context->builder->call($context->lookupFunction('EVP_CIPHER_CTX_free'), $ctx);

        $failFinal = $fn->appendBasicBlock($prefix.'_fail_final');
        $okFinalBb = $fn->appendBasicBlock($prefix.'_ok_final');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okFinal, $i32->constInt(1, false)),
            $failFinal,
            $okFinalBb
        );

        $context->builder->positionAtEnd($failFinal);
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okFinalBb);
        $len2 = $context->builder->load($len2Slot);
        $totalI32 = $context->builder->add($len1, $len2);
        $totalI64 = $context->builder->zExt($totalI32, $i64);

        $wantRaw = $context->builder->icmp(Builder::INT_NE, $rawOutput, $i32->constInt(0, false));
        $rawBb = $fn->appendBasicBlock($prefix.'_ret_raw');
        $b64Bb = $fn->appendBasicBlock($prefix.'_ret_b64');
        $context->builder->branchIf($wantRaw, $rawBb, $b64Bb);

        $context->builder->positionAtEnd($rawBb);
        $rawResult = $context->builder->call($context->lookupFunction('__string__alloc'), $totalI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($totalI64, $context->builder->structGep($rawResult, $strMap['length']));
        $dest = $context->builder->structGep($rawResult, $strMap['value']);
        $context->intrinsic->memcpy(
            $dest,
            $outBuf,
            $context->builder->truncOrBitCast($totalI64, $sizeT),
            false
        );
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->returnValue($rawResult);

        $context->builder->positionAtEnd($b64Bb);
        self::returnBase64($context, $fn, $outBuf, $totalI64, $prefix);
    }

    /**
     * Length-correct base64 (AOT __compiler_base64_encode mishandles binary, #27265).
     */
    private static function returnBase64(
        Context $context,
        LlvmFunction $fn,
        Value $inBuf,
        Value $inLenI64,
        string $prefix
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $strMap = $context->structFieldMap['__string__'];

        // outLen = 4 * ceil(inLen / 3)
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $groups = $context->builder->unsignedDiv(
            $context->builder->add($inLenI64, $i64->constInt(2, false)),
            $three
        );
        $outLen = $context->builder->mul($groups, $four);
        $outStr = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($outStr, $strMap['length']));
        $outPtr = $context->builder->structGep($outStr, $strMap['value']);

        $alpha = $context->builder->pointerCast(
            $context->constantFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'),
            $charPtr
        );

        $iSlot = $context->builder->alloca($i64);
        $oSlot = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->store($i64->constInt(0, false), $oSlot);

        $loop = $fn->appendBasicBlock($prefix.'_b64_loop');
        $done = $fn->appendBasicBlock($prefix.'_b64_done');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $i = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $i, $inLenI64);
        $body = $fn->appendBasicBlock($prefix.'_b64_body');
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $o = $context->builder->load($oSlot);
        $remain = $context->builder->sub($inLenI64, $i);
        $remainI32 = $context->builder->truncOrBitCast($remain, $i32);

        $b0 = $context->builder->load($context->builder->inBoundsGEP($inBuf, $i));
        $b0i = $context->builder->zExt($b0, $i32);

        $has1 = $context->builder->icmp(Builder::INT_SGT, $remainI32, $i32->constInt(1, false));
        $has2 = $context->builder->icmp(Builder::INT_SGT, $remainI32, $i32->constInt(2, false));

        $b1slot = $context->builder->alloca($i32);
        $b2slot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $b1slot);
        $context->builder->store($i32->constInt(0, false), $b2slot);

        $load1 = $fn->appendBasicBlock($prefix.'_b64_load1');
        $after1 = $fn->appendBasicBlock($prefix.'_b64_after1');
        $context->builder->branchIf($has1, $load1, $after1);
        $context->builder->positionAtEnd($load1);
        $b1 = $context->builder->load(
            $context->builder->inBoundsGEP($inBuf, $context->builder->add($i, $i64->constInt(1, false)))
        );
        $context->builder->store($context->builder->zExt($b1, $i32), $b1slot);
        $context->builder->branch($after1);
        $context->builder->positionAtEnd($after1);

        $load2 = $fn->appendBasicBlock($prefix.'_b64_load2');
        $after2 = $fn->appendBasicBlock($prefix.'_b64_after2');
        $context->builder->branchIf($has2, $load2, $after2);
        $context->builder->positionAtEnd($load2);
        $b2 = $context->builder->load(
            $context->builder->inBoundsGEP($inBuf, $context->builder->add($i, $i64->constInt(2, false)))
        );
        $context->builder->store($context->builder->zExt($b2, $i32), $b2slot);
        $context->builder->branch($after2);
        $context->builder->positionAtEnd($after2);

        $b1i = $context->builder->load($b1slot);
        $b2i = $context->builder->load($b2slot);

        $c0 = $context->builder->lShr($b0i, $i32->constInt(2, false));
        $c1 = $context->builder->bitwiseOr(
            $context->builder->bitwiseAnd(
                $context->builder->shl($b0i, $i32->constInt(4, false)),
                $i32->constInt(0x30, false)
            ),
            $context->builder->lShr($b1i, $i32->constInt(4, false))
        );
        $c2 = $context->builder->bitwiseOr(
            $context->builder->bitwiseAnd(
                $context->builder->shl($b1i, $i32->constInt(2, false)),
                $i32->constInt(0x3c, false)
            ),
            $context->builder->lShr($b2i, $i32->constInt(6, false))
        );
        $c3 = $context->builder->bitwiseAnd($b2i, $i32->constInt(0x3f, false));

        $eq = $i8->constInt(ord('='), false);
        $context->builder->store(
            $context->builder->load($context->builder->gep($alpha, $c0)),
            $context->builder->inBoundsGEP($outPtr, $o)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($alpha, $c1)),
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($o, $i64->constInt(1, false)))
        );

        $pad2 = $fn->appendBasicBlock($prefix.'_b64_pad2');
        $char2 = $fn->appendBasicBlock($prefix.'_b64_char2');
        $afterC2 = $fn->appendBasicBlock($prefix.'_b64_after_c2');
        $context->builder->branchIf($has1, $char2, $pad2);
        $context->builder->positionAtEnd($pad2);
        $context->builder->store(
            $eq,
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($o, $i64->constInt(2, false)))
        );
        $context->builder->branch($afterC2);
        $context->builder->positionAtEnd($char2);
        $context->builder->store(
            $context->builder->load($context->builder->gep($alpha, $c2)),
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($o, $i64->constInt(2, false)))
        );
        $context->builder->branch($afterC2);
        $context->builder->positionAtEnd($afterC2);

        $pad3 = $fn->appendBasicBlock($prefix.'_b64_pad3');
        $char3 = $fn->appendBasicBlock($prefix.'_b64_char3');
        $afterC3 = $fn->appendBasicBlock($prefix.'_b64_after_c3');
        $context->builder->branchIf($has2, $char3, $pad3);
        $context->builder->positionAtEnd($pad3);
        $context->builder->store(
            $eq,
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($o, $i64->constInt(3, false)))
        );
        $context->builder->branch($afterC3);
        $context->builder->positionAtEnd($char3);
        $context->builder->store(
            $context->builder->load($context->builder->gep($alpha, $c3)),
            $context->builder->inBoundsGEP($outPtr, $context->builder->add($o, $i64->constInt(3, false)))
        );
        $context->builder->branch($afterC3);
        $context->builder->positionAtEnd($afterC3);

        $context->builder->store($context->builder->add($i, $three), $iSlot);
        $context->builder->store($context->builder->add($o, $four), $oSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($done);
        $context->builder->call($context->lookupFunction('free'), $inBuf);
        $context->builder->returnValue($outStr);
    }

    private static function stringToCstr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI64 = $i64->constInt(1, false);

        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $bytes = $context->builder->structGep($strPtr, $map['value']);
        $bufLen = $context->builder->add($len, $oneI64);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($bufLen, $sizeT)
        );
        $cstr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cstr, $bytes, $context->builder->truncOrBitCast($len, $sizeT), false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($cstr, $len));

        return $cstr;
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function stringLenI64(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($strPtr, $map['length']));
    }

    private static function ensureLibcrypto(Context $context): void
    {
        $ctx = $context->context;
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $i32->pointerType(0);

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'EVP_get_cipherbyname' => [$i8p, false, [$i8p]],
            'EVP_CIPHER_CTX_new' => [$i8p, false, []],
            'EVP_CIPHER_CTX_free' => [$context->context->voidType(), false, [$i8p]],
            'EVP_CIPHER_CTX_set_padding' => [$i32, false, [$i8p, $i32]],
            'EVP_EncryptInit_ex' => [$i32, false, [$i8p, $i8p, $i8p, $i8p, $i8p]],
            'EVP_EncryptUpdate' => [$i32, false, [$i8p, $i8p, $i32p, $i8p, $i32]],
            'EVP_EncryptFinal_ex' => [$i32, false, [$i8p, $i8p, $i32p]],
            'EVP_DecryptInit_ex' => [$i32, false, [$i8p, $i8p, $i8p, $i8p, $i8p]],
            'EVP_DecryptUpdate' => [$i32, false, [$i8p, $i8p, $i32p, $i8p, $i32]],
            'EVP_DecryptFinal_ex' => [$i32, false, [$i8p, $i8p, $i32p]],
        ];

        foreach ($specs as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            try {
                $context->lookupFunction($name);

                continue;
            } catch (\Throwable) {
            }
            $fn = $context->module->addFunction($name, $ctx->functionType($ret, $vararg, ...$params));
            $context->registerFunction($name, $fn);
        }
    }
}
