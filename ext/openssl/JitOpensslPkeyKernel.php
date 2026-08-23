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
 * libcrypto EVP leaves for openssl_pkey_new() / openssl_pkey_get_details() thin AOT (#34015, #34030).
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslPkeyNative} cannot run.
 * Same shape as {@see JitOpensslSignKernel} (#3324).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new) / openssl_pkey_get_details
 */
final class JitOpensslPkeyKernel
{
    public const EVP_RSA_KEYGEN = '__phpc_ossl_pkey_generate_rsa';

    /** PEM → details hashtable (bits / type / key); null on failure (#34030). */
    public const EVP_PKEY_DETAILS = '__phpc_ossl_pkey_get_details';

    private const EVP_PKEY_RSA = 6;

    private const EVP_PKEY_RSA2 = 19;

    private const EVP_PKEY_DSA = 116;

    private const EVP_PKEY_DSA1 = 67;

    private const EVP_PKEY_DSA2 = 66;

    private const EVP_PKEY_DSA3 = 113;

    private const EVP_PKEY_DSA4 = 70;

    private const EVP_PKEY_DH = 28;

    private const EVP_PKEY_EC = 408;

    public static function available(): bool
    {
        return VmOpensslPkeyNative::available();
    }

    public static function ensureKeygenLeaf(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureDetailsLeaf(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (!self::available()) {
            self::implementNullStub($context);

            return;
        }

        LibcExtern::register($context);
        LibcExtern::ensureMallocFamily($context);
        self::ensureLibcrypto($context);

        self::implementKeygenIfMissing($context);
        self::implementDetailsIfMissing($context);
    }

    private static function implementKeygenIfMissing(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::EVP_RSA_KEYGEN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_rsa_entry')) {
            $context->registerFunction(self::EVP_RSA_KEYGEN, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $fn = null !== $probe ? $probe : self::declareKeygenLeaf($context);
        self::emitGenerateRsa($context, $fn);
        $context->registerFunction(self::EVP_RSA_KEYGEN, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementDetailsIfMissing(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::EVP_PKEY_DETAILS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_details_entry')) {
            $context->registerFunction(self::EVP_PKEY_DETAILS, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $fn = null !== $probe ? $probe : self::declareDetailsLeaf($context);
        self::emitGetDetails($context, $fn);
        $context->registerFunction(self::EVP_PKEY_DETAILS, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareKeygenLeaf(Context $context): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $i64);

        return $context->module->addFunction(self::EVP_RSA_KEYGEN, $ft);
    }

    private static function declareDetailsLeaf(Context $context): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $strPtr);

        return $context->module->addFunction(self::EVP_PKEY_DETAILS, $ft);
    }

    private static function implementNullStub(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::EVP_RSA_KEYGEN);
        if (!JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_rsa_stub')) {
            $fn = null !== $probe ? $probe : self::declareKeygenLeaf($context);
            $block = $fn->appendBasicBlock('ossl_llvm_pkey_rsa_stub');
            $context->builder->positionAtEnd($block);
            $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
            $context->registerFunction(self::EVP_RSA_KEYGEN, $fn);
            $context->builder->clearInsertionPosition();
        } else {
            $context->registerFunction(self::EVP_RSA_KEYGEN, $probe);
        }

        $probeD = $context->module->getNamedFunction(self::EVP_PKEY_DETAILS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probeD, 'ossl_llvm_pkey_details_stub')) {
            $context->registerFunction(self::EVP_PKEY_DETAILS, $probeD);

            return;
        }
        $fnD = null !== $probeD ? $probeD : self::declareDetailsLeaf($context);
        $blockD = $fnD->appendBasicBlock('ossl_llvm_pkey_details_stub');
        $context->builder->positionAtEnd($blockD);
        $context->builder->returnValue($context->getTypeFromString('__hashtable__*')->constNull());
        $context->registerFunction(self::EVP_PKEY_DETAILS, $fnD);
        $context->builder->clearInsertionPosition();
    }

    private static function emitGenerateRsa(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_rsa_entry');
        $context->builder->positionAtEnd($entry);

        $bitsI64 = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $voidp = $i8p;

        $bitsI32 = $context->builder->truncOrBitCast($bitsI64, $i32);
        $tooSmall = $context->builder->icmp(
            Builder::INT_SLT,
            $bitsI64,
            $i64->constInt(384, false)
        );
        $failBits = $fn->appendBasicBlock('ossl_pkey_fail_bits');
        $haveBits = $fn->appendBasicBlock('ossl_pkey_have_bits');
        $context->builder->branchIf($tooSmall, $failBits, $haveBits);

        $context->builder->positionAtEnd($failBits);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBits);
        $ctx = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_CTX_new_id'),
            $i32->constInt(self::EVP_PKEY_RSA, false),
            $voidp->constNull()
        );
        $failCtx = $fn->appendBasicBlock('ossl_pkey_fail_ctx');
        $haveCtx = $fn->appendBasicBlock('ossl_pkey_have_ctx');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ctx, $i8p->constNull()),
            $failCtx,
            $haveCtx
        );
        $context->builder->positionAtEnd($failCtx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveCtx);
        $okInit = $context->builder->call($context->lookupFunction('EVP_PKEY_keygen_init'), $ctx);
        $failInit = $fn->appendBasicBlock('ossl_pkey_fail_init');
        $afterInit = $fn->appendBasicBlock('ossl_pkey_after_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okInit, $i32->constInt(1, false)),
            $failInit,
            $afterInit
        );
        $context->builder->positionAtEnd($failInit);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterInit);
        $okBits = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_CTX_set_rsa_keygen_bits'),
            $ctx,
            $bitsI32
        );
        $failSetBits = $fn->appendBasicBlock('ossl_pkey_fail_set_bits');
        $afterSetBits = $fn->appendBasicBlock('ossl_pkey_after_set_bits');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okBits, $i32->constInt(1, false)),
            $failSetBits,
            $afterSetBits
        );
        $context->builder->positionAtEnd($failSetBits);
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterSetBits);
        $pkeySlot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $pkeySlot);
        $okKeygen = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_keygen'),
            $ctx,
            $pkeySlot
        );
        $context->builder->call($context->lookupFunction('EVP_PKEY_CTX_free'), $ctx);
        $failKeygen = $fn->appendBasicBlock('ossl_pkey_fail_keygen');
        $havePkey = $fn->appendBasicBlock('ossl_pkey_have_pkey');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okKeygen, $i32->constInt(1, false)),
            $failKeygen,
            $havePkey
        );
        $context->builder->positionAtEnd($failKeygen);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($havePkey);
        $pkey = $context->builder->load($pkeySlot);
        $failPkeyNull = $fn->appendBasicBlock('ossl_pkey_fail_pkey_null');
        $havePkeyNonNull = $fn->appendBasicBlock('ossl_pkey_have_pkey_nn');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkey, $i8p->constNull()),
            $failPkeyNull,
            $havePkeyNonNull
        );
        $context->builder->positionAtEnd($failPkeyNull);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($havePkeyNonNull);
        $bioMethod = $context->builder->call($context->lookupFunction('BIO_s_mem'));
        $bio = $context->builder->call($context->lookupFunction('BIO_new'), $bioMethod);
        $failBio = $fn->appendBasicBlock('ossl_pkey_fail_bio');
        $haveBio = $fn->appendBasicBlock('ossl_pkey_have_bio');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );
        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBio);
        $okPem = $context->builder->call(
            $context->lookupFunction('PEM_write_bio_PrivateKey'),
            $bio,
            $pkey,
            $voidp->constNull(),
            $voidp->constNull(),
            $i32->constInt(0, false),
            $voidp->constNull(),
            $voidp->constNull()
        );
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $failPem = $fn->appendBasicBlock('ossl_pkey_fail_pem');
        $havePem = $fn->appendBasicBlock('ossl_pkey_have_pem');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okPem, $i32->constInt(1, false)),
            $failPem,
            $havePem
        );
        $context->builder->positionAtEnd($failPem);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($havePem);
        $pending = $context->builder->call($context->lookupFunction('BIO_ctrl_pending'), $bio);
        $pendingI64 = $context->builder->zExt($pending, $i64);
        $failPending = $fn->appendBasicBlock('ossl_pkey_fail_pending');
        $havePending = $fn->appendBasicBlock('ossl_pkey_have_pending');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $pendingI64, $i64->constInt(0, false)),
            $failPending,
            $havePending
        );
        $context->builder->positionAtEnd($failPending);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($havePending);
        $pendingI32 = $context->builder->truncOrBitCast($pending, $i32);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $pending);
        $failMalloc = $fn->appendBasicBlock('ossl_pkey_fail_malloc');
        $haveBuf = $fn->appendBasicBlock('ossl_pkey_have_buf');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull()),
            $failMalloc,
            $haveBuf
        );
        $context->builder->positionAtEnd($failMalloc);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBuf);
        $read = $context->builder->call(
            $context->lookupFunction('BIO_read'),
            $bio,
            $buf,
            $pendingI32
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $failRead = $fn->appendBasicBlock('ossl_pkey_fail_read');
        $okRead = $fn->appendBasicBlock('ossl_pkey_ok_read');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $read, $i32->constInt(0, false)),
            $failRead,
            $okRead
        );
        $context->builder->positionAtEnd($failRead);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okRead);
        $readI64 = $context->builder->zExt($read, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $readI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($readI64, $context->builder->structGep($result, $strMap['length']));
        $dest = $context->builder->structGep($result, $strMap['value']);
        $context->intrinsic->memcpy($dest, $buf, $readI64, false);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);
    }

    /**
     * openssl_pkey_get_details leaf: PEM → {bits, type, key} hashtable (#34030).
     *
     * Done-when requires bits/type/key; rsa/ec/dh sub-arrays stay NestedJIT/VM-only for now.
     */
    private static function emitGetDetails(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_details_entry');
        $context->builder->positionAtEnd($entry);

        $pemStr = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidp = $i8p;

        $pkeySlot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $pkeySlot);
        $pkeyReady = $fn->appendBasicBlock('ossl_details_pkey_ready');

        $failPemNull = $fn->appendBasicBlock('ossl_details_fail_pem_null');
        $havePem = $fn->appendBasicBlock('ossl_details_have_pem');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pemStr, $strPtr->constNull()),
            $failPemNull,
            $havePem
        );
        $context->builder->positionAtEnd($failPemNull);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($havePem);
        $pemLen = self::stringLenI64($context, $pemStr);
        $failEmpty = $fn->appendBasicBlock('ossl_details_fail_empty');
        $haveLen = $fn->appendBasicBlock('ossl_details_have_len');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $pemLen, $i64->constInt(0, false)),
            $failEmpty,
            $haveLen
        );
        $context->builder->positionAtEnd($failEmpty);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($haveLen);
        $pemCstr = self::stringToCstr($context, $pemStr);
        $pemLenI32 = $context->builder->truncOrBitCast($pemLen, $i32);
        $bio = $context->builder->call(
            $context->lookupFunction('BIO_new_mem_buf'),
            $pemCstr,
            $pemLenI32
        );
        $failBio = $fn->appendBasicBlock('ossl_details_fail_bio');
        $haveBio = $fn->appendBasicBlock('ossl_details_have_bio');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );
        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($haveBio);
        $pkeyPriv = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio,
            $voidp->constNull(),
            $voidp->constNull(),
            $voidp->constNull()
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $tryPub = $fn->appendBasicBlock('ossl_details_try_pub');
        $gotPriv = $fn->appendBasicBlock('ossl_details_got_priv');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkeyPriv, $i8p->constNull()),
            $tryPub,
            $gotPriv
        );

        $context->builder->positionAtEnd($gotPriv);
        $context->builder->store($pkeyPriv, $pkeySlot);
        $context->builder->branch($pkeyReady);

        $context->builder->positionAtEnd($tryPub);
        $bio2 = $context->builder->call(
            $context->lookupFunction('BIO_new_mem_buf'),
            $pemCstr,
            $pemLenI32
        );
        $failBio2 = $fn->appendBasicBlock('ossl_details_fail_bio2');
        $haveBio2 = $fn->appendBasicBlock('ossl_details_have_bio2');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio2, $i8p->constNull()),
            $failBio2,
            $haveBio2
        );
        $context->builder->positionAtEnd($failBio2);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($haveBio2);
        $pkeyPub = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PUBKEY'),
            $bio2,
            $voidp->constNull(),
            $voidp->constNull(),
            $voidp->constNull()
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio2);
        $failPub = $fn->appendBasicBlock('ossl_details_fail_pub');
        $gotPub = $fn->appendBasicBlock('ossl_details_got_pub');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkeyPub, $i8p->constNull()),
            $failPub,
            $gotPub
        );
        $context->builder->positionAtEnd($failPub);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($gotPub);
        $context->builder->store($pkeyPub, $pkeySlot);
        $context->builder->branch($pkeyReady);

        $context->builder->positionAtEnd($pkeyReady);
        $pkeyLive = $context->builder->load($pkeySlot);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);

        $bitsI32 = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_get_bits'),
            $pkeyLive
        );
        $bitsI64 = $context->builder->sext($bitsI32, $i64);
        $failBits = $fn->appendBasicBlock('ossl_details_fail_bits');
        $haveBits = $fn->appendBasicBlock('ossl_details_have_bits');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $bitsI64, $i64->constInt(0, false)),
            $failBits,
            $haveBits
        );
        $context->builder->positionAtEnd($failBits);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkeyLive);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($haveBits);
        $baseId = $context->builder->call(
            $context->lookupFunction('EVP_PKEY_get_base_id'),
            $pkeyLive
        );
        $typeSlot = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(-1, true), $typeSlot);
        self::emitTypeMatch($context, $fn, $baseId, $typeSlot);

        $pubPem = self::emitWritePublicPem($context, $fn, $pkeyLive);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkeyLive);
        $failPubPem = $fn->appendBasicBlock('ossl_details_fail_pubpem');
        $havePubPem = $fn->appendBasicBlock('ossl_details_have_pubpem');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pubPem, $strPtr->constNull()),
            $failPubPem,
            $havePubPem
        );
        $context->builder->positionAtEnd($failPubPem);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($havePubPem);
        $typeVal = $context->builder->load($typeSlot);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString('bits')),
            $bitsI64
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString('type')),
            $typeVal
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->load($context->constantStringFromString('key')),
            $pubPem
        );
        $context->builder->returnValue($ht);
    }

    /**
     * @param Value $typeSlot alloca int64
     */
    private static function emitTypeMatch(
        Context $context,
        LlvmFunction $fn,
        Value $baseId,
        Value $typeSlot
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $pairs = [
            [self::EVP_PKEY_RSA, OpensslConstants::OPENSSL_KEYTYPE_RSA],
            [self::EVP_PKEY_RSA2, OpensslConstants::OPENSSL_KEYTYPE_RSA],
            [self::EVP_PKEY_DSA, OpensslConstants::OPENSSL_KEYTYPE_DSA],
            [self::EVP_PKEY_DSA1, OpensslConstants::OPENSSL_KEYTYPE_DSA],
            [self::EVP_PKEY_DSA2, OpensslConstants::OPENSSL_KEYTYPE_DSA],
            [self::EVP_PKEY_DSA3, OpensslConstants::OPENSSL_KEYTYPE_DSA],
            [self::EVP_PKEY_DSA4, OpensslConstants::OPENSSL_KEYTYPE_DSA],
            [self::EVP_PKEY_DH, OpensslConstants::OPENSSL_KEYTYPE_DH],
            [self::EVP_PKEY_EC, OpensslConstants::OPENSSL_KEYTYPE_EC],
        ];
        $next = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('ossl_details_type_done');
        foreach ($pairs as $i => [$nid, $phpType]) {
            $context->builder->positionAtEnd($next);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $baseId,
                $i32->constInt($nid, false)
            );
            $hit = $fn->appendBasicBlock('ossl_details_type_hit_'.$i);
            $next = $fn->appendBasicBlock('ossl_details_type_next_'.$i);
            $context->builder->branchIf($match, $hit, $next);
            $context->builder->positionAtEnd($hit);
            $context->builder->store($i64->constInt($phpType, true), $typeSlot);
            $context->builder->branch($done);
        }
        $context->builder->positionAtEnd($next);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitWritePublicPem(
        Context $context,
        LlvmFunction $fn,
        Value $pkey
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $slot = $context->builder->alloca($strPtr);
        $context->builder->store($nullStr, $slot);

        $done = $fn->appendBasicBlock('ossl_details_pubpem_done');
        $bioMethod = $context->builder->call($context->lookupFunction('BIO_s_mem'));
        $bio = $context->builder->call($context->lookupFunction('BIO_new'), $bioMethod);
        $failBio = $fn->appendBasicBlock('ossl_details_pubpem_fail_bio');
        $haveBio = $fn->appendBasicBlock('ossl_details_pubpem_have_bio');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );
        $context->builder->positionAtEnd($failBio);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($haveBio);
        $okPem = $context->builder->call(
            $context->lookupFunction('PEM_write_bio_PUBKEY'),
            $bio,
            $pkey
        );
        $failPem = $fn->appendBasicBlock('ossl_details_pubpem_fail_write');
        $havePem = $fn->appendBasicBlock('ossl_details_pubpem_have_write');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okPem, $i32->constInt(1, false)),
            $failPem,
            $havePem
        );
        $context->builder->positionAtEnd($failPem);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($havePem);
        $pending = $context->builder->call($context->lookupFunction('BIO_ctrl_pending'), $bio);
        $pendingI64 = $context->builder->zExt($pending, $i64);
        $failPending = $fn->appendBasicBlock('ossl_details_pubpem_fail_pending');
        $havePending = $fn->appendBasicBlock('ossl_details_pubpem_have_pending');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $pendingI64, $i64->constInt(0, false)),
            $failPending,
            $havePending
        );
        $context->builder->positionAtEnd($failPending);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($havePending);
        $pendingI32 = $context->builder->truncOrBitCast($pending, $i32);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $pending);
        $failMalloc = $fn->appendBasicBlock('ossl_details_pubpem_fail_malloc');
        $haveBuf = $fn->appendBasicBlock('ossl_details_pubpem_have_buf');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull()),
            $failMalloc,
            $haveBuf
        );
        $context->builder->positionAtEnd($failMalloc);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($haveBuf);
        $read = $context->builder->call(
            $context->lookupFunction('BIO_read'),
            $bio,
            $buf,
            $pendingI32
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $failRead = $fn->appendBasicBlock('ossl_details_pubpem_fail_read');
        $okRead = $fn->appendBasicBlock('ossl_details_pubpem_ok_read');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $read, $i32->constInt(0, false)),
            $failRead,
            $okRead
        );
        $context->builder->positionAtEnd($failRead);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($okRead);
        $readI64 = $context->builder->zExt($read, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $readI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($readI64, $context->builder->structGep($result, $strMap['length']));
        $dest = $context->builder->structGep($result, $strMap['value']);
        $context->intrinsic->memcpy($dest, $buf, $readI64, false);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->store($result, $slot);
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
        $i8pp = $i8p->pointerType(0);
        $voidp = $i8p;
        $sizeT = $context->getTypeFromString('size_t');

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'EVP_PKEY_CTX_new_id' => [$i8p, false, [$i32, $voidp]],
            'EVP_PKEY_CTX_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_PKEY_keygen_init' => [$i32, false, [$i8p]],
            'EVP_PKEY_CTX_set_rsa_keygen_bits' => [$i32, false, [$i8p, $i32]],
            'EVP_PKEY_keygen' => [$i32, false, [$i8p, $i8pp]],
            'EVP_PKEY_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_PKEY_get_bits' => [$i32, false, [$i8p]],
            'EVP_PKEY_get_base_id' => [$i32, false, [$i8p]],
            'BIO_s_mem' => [$i8p, false, []],
            'BIO_new' => [$i8p, false, [$i8p]],
            'BIO_new_mem_buf' => [$i8p, false, [$voidp, $i32]],
            'BIO_free' => [$ctx->voidType(), false, [$i8p]],
            'BIO_ctrl_pending' => [$sizeT, false, [$i8p]],
            'BIO_read' => [$i32, false, [$i8p, $voidp, $i32]],
            'PEM_write_bio_PrivateKey' => [$i32, false, [$i8p, $i8p, $voidp, $voidp, $i32, $voidp, $voidp]],
            'PEM_write_bio_PUBKEY' => [$i32, false, [$i8p, $i8p]],
            'PEM_read_bio_PrivateKey' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
            'PEM_read_bio_PUBKEY' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
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
