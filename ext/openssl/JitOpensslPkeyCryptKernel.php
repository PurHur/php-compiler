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
 * libcrypto EVP asymmetric encrypt/decrypt leaves for thin AOT (#34722).
 *
 * Bake-only {@see JitOpensslX509} paths require compile-time PEM literals; runtime
 * OpenSSLAsymmetricKey / runtime strings need these leaves (peer {@see JitOpensslSignKernel}).
 *
 * php-src: ext/openssl/openssl.c — openssl_public_encrypt / private_encrypt /
 * private_decrypt / public_decrypt
 */
final class JitOpensslPkeyCryptKernel
{
    public const EVP_PUBLIC_ENCRYPT = '__phpc_ossl_pkey_public_encrypt';

    public const EVP_PRIVATE_ENCRYPT = '__phpc_ossl_pkey_private_encrypt';

    public const EVP_PRIVATE_DECRYPT = '__phpc_ossl_pkey_private_decrypt';

    public const EVP_PUBLIC_DECRYPT = '__phpc_ossl_pkey_public_decrypt';

    public static function available(): bool
    {
        return VmOpensslPkeyNative::available();
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
        LibcExtern::ensureMallocFamily($context);
        self::ensureLibcrypto($context);

        self::implementIfMissing(
            $context,
            self::EVP_PUBLIC_ENCRYPT,
            'ossl_llvm_pubkey_enc_entry',
            static function (Context $context, LlvmFunction $fn): void {
                self::emitCrypt($context, $fn, 'ossl_pubenc', false, 'EVP_PKEY_encrypt_init', 'EVP_PKEY_encrypt');
            }
        );
        self::implementIfMissing(
            $context,
            self::EVP_PRIVATE_ENCRYPT,
            'ossl_llvm_privenc_entry',
            static function (Context $context, LlvmFunction $fn): void {
                self::emitCrypt($context, $fn, 'ossl_privenc', true, 'EVP_PKEY_sign_init', 'EVP_PKEY_sign');
            }
        );
        self::implementIfMissing(
            $context,
            self::EVP_PRIVATE_DECRYPT,
            'ossl_llvm_privdec_entry',
            static function (Context $context, LlvmFunction $fn): void {
                self::emitCrypt($context, $fn, 'ossl_privdec', true, 'EVP_PKEY_decrypt_init', 'EVP_PKEY_decrypt');
            }
        );
        self::implementIfMissing(
            $context,
            self::EVP_PUBLIC_DECRYPT,
            'ossl_llvm_pubdec_entry',
            static function (Context $context, LlvmFunction $fn): void {
                self::emitCrypt($context, $fn, 'ossl_pubdec', false, 'EVP_PKEY_verify_recover_init', 'EVP_PKEY_verify_recover');
            }
        );
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(
        Context $context,
        string $abiName,
        string $entryName,
        callable $emit
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
        $emit($context, $fn);
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
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64);

        return $context->module->addFunction($abiName, $ft);
    }

    private static function implementNullStubs(Context $context): void
    {
        foreach ([
            self::EVP_PUBLIC_ENCRYPT => 'ossl_llvm_pubkey_enc_stub',
            self::EVP_PRIVATE_ENCRYPT => 'ossl_llvm_privenc_stub',
            self::EVP_PRIVATE_DECRYPT => 'ossl_llvm_privdec_stub',
            self::EVP_PUBLIC_DECRYPT => 'ossl_llvm_pubdec_stub',
        ] as $abi => $entry) {
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

    /**
     * Shared EVP_PKEY_CTX two-phase crypt (php-src openssl.c asymmetric helpers).
     *
     * @param bool $privateKey true → PEM_read_bio_PrivateKey; false → PUBKEY then PrivateKey
     */
    private static function emitCrypt(
        Context $context,
        LlvmFunction $fn,
        string $prefix,
        bool $privateKey,
        string $initName,
        string $cryptName
    ): void {
        $entry = $fn->appendBasicBlock($prefix.'_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $keyPem = $fn->getParam(1);
        $padding = $fn->getParam(2);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullImpl = $i8p->constNull();

        $pkey = $privateKey
            ? self::readPrivateKey($context, $fn, $keyPem, $prefix)
            : self::readAnyKey($context, $fn, $keyPem, $prefix);

        $failKey = $fn->appendBasicBlock($prefix.'_fail_key');
        $haveKey = $fn->appendBasicBlock($prefix.'_have_key');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkey, $i8p->constNull()),
            $failKey,
            $haveKey
        );
        $context->builder->positionAtEnd($failKey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveKey);
        $ctx = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_CTX_new'),
            $pkey,
            $nullImpl
        );
        $failCtx = $fn->appendBasicBlock($prefix.'_fail_ctx');
        $haveCtx = $fn->appendBasicBlock($prefix.'_have_ctx');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ctx, $i8p->constNull()),
            $failCtx,
            $haveCtx
        );
        $context->builder->positionAtEnd($failCtx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveCtx);
        $okInit = $context->builder->call($context->lookupFunction($initName), $ctx);
        $failInit = $fn->appendBasicBlock($prefix.'_fail_init');
        $afterInit = $fn->appendBasicBlock($prefix.'_after_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okInit, $i32->constInt(1, false)),
            $failInit,
            $afterInit
        );
        $context->builder->positionAtEnd($failInit);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterInit);
        $padI32 = $context->builder->truncOrBitCast($padding, $i32);
        $okPad = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_CTX_set_rsa_padding'),
            $ctx,
            $padI32
        );
        $failPad = $fn->appendBasicBlock($prefix.'_fail_pad');
        $afterPad = $fn->appendBasicBlock($prefix.'_after_pad');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okPad, $i32->constInt(1, false)),
            $failPad,
            $afterPad
        );
        $context->builder->positionAtEnd($failPad);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterPad);
        $inLenI64 = self::stringLenI64($context, $data);
        $failEmpty = $fn->appendBasicBlock($prefix.'_fail_empty');
        $haveData = $fn->appendBasicBlock($prefix.'_have_data');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $inLenI64, $i64->constInt(0, false)),
            $failEmpty,
            $haveData
        );
        $context->builder->positionAtEnd($failEmpty);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveData);
        $inPtr = self::stringData($context, $data);
        $inLen = $context->builder->truncOrBitCast($inLenI64, $sizeT);
        $outlenSlot = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $outlenSlot);
        $okLen = $context->builder->call(
            $context->lookupFunction($cryptName),
            $ctx,
            $nullImpl,
            $outlenSlot,
            $inPtr,
            $inLen
        );
        $failLen = $fn->appendBasicBlock($prefix.'_fail_len');
        $haveLen = $fn->appendBasicBlock($prefix.'_have_len');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okLen, $i32->constInt(1, false)),
            $failLen,
            $haveLen
        );
        $context->builder->positionAtEnd($failLen);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveLen);
        $outLen = $context->builder->load($outlenSlot);
        $outLenI64 = $context->builder->zExt($outLen, $i64);
        $failOutLen = $fn->appendBasicBlock($prefix.'_fail_outlen');
        $haveOutLen = $fn->appendBasicBlock($prefix.'_have_outlen');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $outLenI64, $i64->constInt(0, false)),
            $failOutLen,
            $haveOutLen
        );
        $context->builder->positionAtEnd($failOutLen);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveOutLen);
        $outBuf = $context->builder->call($context->lookupFunction('malloc'), $outLen);
        $failMalloc = $fn->appendBasicBlock($prefix.'_fail_malloc');
        $haveBuf = $fn->appendBasicBlock($prefix.'_have_buf');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $outBuf, $i8p->constNull()),
            $failMalloc,
            $haveBuf
        );
        $context->builder->positionAtEnd($failMalloc);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBuf);
        $okCrypt = $context->builder->call(
            $context->lookupFunction($cryptName),
            $ctx,
            $outBuf,
            $outlenSlot,
            $inPtr,
            $inLen
        );
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $failCrypt = $fn->appendBasicBlock($prefix.'_fail_crypt');
        $okDone = $fn->appendBasicBlock($prefix.'_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okCrypt, $i32->constInt(1, false)),
            $failCrypt,
            $okDone
        );
        $context->builder->positionAtEnd($failCrypt);
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okDone);
        $finalLen = $context->builder->load($outlenSlot);
        $finalLenI64 = $context->builder->zExt($finalLen, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $finalLenI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($finalLenI64, $context->builder->structGep($result, $strMap['length']));
        $dest = $context->builder->structGep($result, $strMap['value']);
        $context->intrinsic->memcpy($dest, $outBuf, $finalLenI64, false);
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->returnValue($result);
    }

    private static function readPrivateKey(Context $context, LlvmFunction $fn, Value $pemStr, string $prefix): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $nullImpl = $i8p->constNull();

        $pemCstr = self::stringToCstr($context, $pemStr);
        $pemLenI32 = $context->builder->truncOrBitCast(self::stringLenI64($context, $pemStr), $i32);
        $bio = $context->builder->call($context->lookupFunction('BIO_new_mem_buf'), $pemCstr, $pemLenI32);

        $done = $fn->appendBasicBlock($prefix.'_read_priv_done');
        $slot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $slot);

        $failBio = $fn->appendBasicBlock($prefix.'_fail_bio');
        $haveBio = $fn->appendBasicBlock($prefix.'_have_bio');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );

        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($haveBio);
        $pkey = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio,
            $nullImpl,
            $nullImpl,
            $nullImpl
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($pkey, $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($slot);
    }

    /** PUBKEY first, then PrivateKey (php-src php_openssl_pkey_from_zval / readAnyKey). */
    private static function readAnyKey(Context $context, LlvmFunction $fn, Value $pemStr, string $prefix): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $nullImpl = $i8p->constNull();

        $pemCstr = self::stringToCstr($context, $pemStr);
        $pemLenI32 = $context->builder->truncOrBitCast(self::stringLenI64($context, $pemStr), $i32);
        $bio = $context->builder->call($context->lookupFunction('BIO_new_mem_buf'), $pemCstr, $pemLenI32);

        $done = $fn->appendBasicBlock($prefix.'_read_any_done');
        $slot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $slot);

        $failBio = $fn->appendBasicBlock($prefix.'_fail_bio_any');
        $haveBio = $fn->appendBasicBlock($prefix.'_have_bio_any');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );

        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($haveBio);
        $pub = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PUBKEY'),
            $bio,
            $nullImpl,
            $nullImpl,
            $nullImpl
        );
        $failPub = $fn->appendBasicBlock($prefix.'_fail_pub');
        $gotPub = $fn->appendBasicBlock($prefix.'_got_pub');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pub, $i8p->constNull()),
            $failPub,
            $gotPub
        );

        $context->builder->positionAtEnd($gotPub);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($pub, $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($failPub);
        // Rewind: free BIO and retry PrivateKey from a fresh mem BIO.
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $bio2 = $context->builder->call($context->lookupFunction('BIO_new_mem_buf'), $pemCstr, $pemLenI32);
        $failBio2 = $fn->appendBasicBlock($prefix.'_fail_bio2');
        $haveBio2 = $fn->appendBasicBlock($prefix.'_have_bio2');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio2, $i8p->constNull()),
            $failBio2,
            $haveBio2
        );

        $context->builder->positionAtEnd($failBio2);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($haveBio2);
        $priv = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio2,
            $nullImpl,
            $nullImpl,
            $nullImpl
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio2);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($priv, $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($slot);
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
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);
        $voidp = $i8p;

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'BIO_new_mem_buf' => [$i8p, false, [$voidp, $i32]],
            'BIO_free' => [$ctx->voidType(), false, [$i8p]],
            'PEM_read_bio_PrivateKey' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
            'PEM_read_bio_PUBKEY' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
            'EVP_PKEY_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_PKEY_CTX_new' => [$i8p, false, [$i8p, $voidp]],
            'EVP_PKEY_CTX_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_PKEY_CTX_set_rsa_padding' => [$i32, false, [$i8p, $i32]],
            'EVP_PKEY_encrypt_init' => [$i32, false, [$i8p]],
            'EVP_PKEY_encrypt' => [$i32, false, [$i8p, $i8p, $sizeTp, $i8p, $sizeT]],
            'EVP_PKEY_sign_init' => [$i32, false, [$i8p]],
            'EVP_PKEY_sign' => [$i32, false, [$i8p, $i8p, $sizeTp, $i8p, $sizeT]],
            'EVP_PKEY_decrypt_init' => [$i32, false, [$i8p]],
            'EVP_PKEY_decrypt' => [$i32, false, [$i8p, $i8p, $sizeTp, $i8p, $sizeT]],
            'EVP_PKEY_verify_recover_init' => [$i32, false, [$i8p]],
            'EVP_PKEY_verify_recover' => [$i32, false, [$i8p, $i8p, $sizeTp, $i8p, $sizeT]],
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
