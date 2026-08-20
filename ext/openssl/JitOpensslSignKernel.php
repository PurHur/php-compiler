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
 * libcrypto EVP sign/verify NestedJIT leaves for openssl_sign()/openssl_verify() AOT (#3324).
 *
 * Thin-standalone AOT has no FFI ({@see extension_loaded}('ffi') → false), so NestedJIT
 * {@see OpensslSignJitHelper} + {@see VmOpensslSignNative} cannot sign. Same shape as
 * {@see JitOpensslCipherKernel} (#27265).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_sign) / openssl_verify
 */
final class JitOpensslSignKernel
{
    public const EVP_SIGN = '__phpc_ossl_evp_sign';

    public const EVP_VERIFY = '__phpc_ossl_evp_verify';

    /** Max RSA/EC signature size we allocate. */
    private const MAX_SIG_BYTES = 512;

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
        LibcExtern::ensureMallocFamily($context);
        self::ensureLibcrypto($context);

        self::implementIfMissing($context, self::EVP_SIGN, 'ossl_llvm_sign_entry', self::emitSign(...));
        self::implementIfMissing($context, self::EVP_VERIFY, 'ossl_llvm_verify_entry', self::emitVerify(...));
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

        $fn = null !== $probe ? $probe : self::declareSignLeaf($context, $abiName);
        $emit($context, $fn);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareSignLeaf(Context $context, string $abiName): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        if (self::EVP_VERIFY === $abiName) {
            $i32 = $context->getTypeFromString('int32');
            $ft = $context->context->functionType($i32, false, $strPtr, $strPtr, $strPtr, $i64);

            return $context->module->addFunction($abiName, $ft);
        }

        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64);

        return $context->module->addFunction($abiName, $ft);
    }

    private static function implementNullStubs(Context $context): void
    {
        foreach ([self::EVP_SIGN => 'ossl_llvm_sign_stub', self::EVP_VERIFY => 'ossl_llvm_verify_stub'] as $abi => $entry) {
            $probe = $context->module->getNamedFunction($abi);
            if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
                $context->registerFunction($abi, $probe);

                continue;
            }
            $fn = null !== $probe ? $probe : self::declareSignLeaf($context, $abi);
            $block = $fn->appendBasicBlock($entry);
            $context->builder->positionAtEnd($block);
            if (self::EVP_VERIFY === $abi) {
                $context->builder->returnValue($context->getTypeFromString('int32')->constInt(-1, true));
            } else {
                $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
            }
            $context->registerFunction($abi, $fn);
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitSign(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_sign_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $keyPem = $fn->getParam(1);
        $algo = $fn->getParam(2);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullImpl = $i8p->constNull();

        $digestCstr = self::resolveDigestCstr($context, $fn, $algo, 'ossl_sign');
        $failDigest = $fn->appendBasicBlock('ossl_sign_fail_digest');
        $haveDigest = $fn->appendBasicBlock('ossl_sign_have_digest');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $digestCstr, $i8p->constNull()),
            $failDigest,
            $haveDigest
        );

        $context->builder->positionAtEnd($failDigest);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveDigest);
        $md = $context->builder->call($context->lookupFunction('EVP_get_digestbyname'), $digestCstr);
        $context->builder->call($context->lookupFunction('free'), $digestCstr);

        $failMd = $fn->appendBasicBlock('ossl_sign_fail_md');
        $haveMd = $fn->appendBasicBlock('ossl_sign_have_md');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $md, $i8p->constNull()),
            $failMd,
            $haveMd
        );
        $context->builder->positionAtEnd($failMd);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveMd);
        $pkey = self::readPrivateKey($context, $fn, $keyPem, 'ossl_sign');
        $failKey = $fn->appendBasicBlock('ossl_sign_fail_key');
        $haveKey = $fn->appendBasicBlock('ossl_sign_have_key');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkey, $i8p->constNull()),
            $failKey,
            $haveKey
        );
        $context->builder->positionAtEnd($failKey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveKey);
        $ctx = $context->builder->call($context->lookupFunction('EVP_MD_CTX_new'));
        $failCtx = $fn->appendBasicBlock('ossl_sign_fail_ctx');
        $haveCtx = $fn->appendBasicBlock('ossl_sign_have_ctx');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ctx, $i8p->constNull()),
            $failCtx,
            $haveCtx
        );
        $context->builder->positionAtEnd($failCtx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveCtx);
        $okInit = $context->builder->call(
            $context->lookupFunction('EVP_DigestSignInit'),
            $ctx,
            $nullImpl,
            $md,
            $nullImpl,
            $pkey
        );
        $failInit = $fn->appendBasicBlock('ossl_sign_fail_init');
        $afterInit = $fn->appendBasicBlock('ossl_sign_after_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okInit, $i32->constInt(1, false)),
            $failInit,
            $afterInit
        );
        $context->builder->positionAtEnd($failInit);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterInit);
        $dataPtr = self::stringData($context, $data);
        $dataLen = $context->builder->truncOrBitCast(self::stringLenI64($context, $data), $sizeT);
        $okUpdate = $context->builder->call(
            $context->lookupFunction('EVP_DigestSignUpdate'),
            $ctx,
            $dataPtr,
            $dataLen
        );
        $failUpdate = $fn->appendBasicBlock('ossl_sign_fail_update');
        $afterUpdate = $fn->appendBasicBlock('ossl_sign_after_update');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okUpdate, $i32->constInt(1, false)),
            $failUpdate,
            $afterUpdate
        );
        $context->builder->positionAtEnd($failUpdate);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterUpdate);
        $siglenSlot = $context->builder->alloca($sizeT);
        $context->builder->store($sizeT->constInt(0, false), $siglenSlot);
        $okLen = $context->builder->call(
            $context->lookupFunction('EVP_DigestSignFinal'),
            $ctx,
            $nullImpl,
            $siglenSlot
        );
        $failLen = $fn->appendBasicBlock('ossl_sign_fail_len');
        $haveLen = $fn->appendBasicBlock('ossl_sign_have_len');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okLen, $i32->constInt(1, false)),
            $failLen,
            $haveLen
        );
        $context->builder->positionAtEnd($failLen);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveLen);
        $sigLen = $context->builder->load($siglenSlot);
        $sigLenI64 = $context->builder->zExt($sigLen, $i64);
        $failSigLen = $fn->appendBasicBlock('ossl_sign_fail_siglen');
        $haveSigLen = $fn->appendBasicBlock('ossl_sign_have_siglen');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $sigLenI64, $i64->constInt(0, false)),
            $failSigLen,
            $haveSigLen
        );
        $context->builder->positionAtEnd($failSigLen);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveSigLen);
        $sigBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $sigLen
        );
        $failMalloc = $fn->appendBasicBlock('ossl_sign_fail_malloc');
        $haveBuf = $fn->appendBasicBlock('ossl_sign_have_buf');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $sigBuf, $i8p->constNull()),
            $failMalloc,
            $haveBuf
        );
        $context->builder->positionAtEnd($failMalloc);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBuf);
        $okFinal = $context->builder->call(
            $context->lookupFunction('EVP_DigestSignFinal'),
            $ctx,
            $sigBuf,
            $siglenSlot
        );
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);

        $failFinal = $fn->appendBasicBlock('ossl_sign_fail_final');
        $okFinalBb = $fn->appendBasicBlock('ossl_sign_ok_final');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okFinal, $i32->constInt(1, false)),
            $failFinal,
            $okFinalBb
        );
        $context->builder->positionAtEnd($failFinal);
        $context->builder->call($context->lookupFunction('free'), $sigBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okFinalBb);
        $finalLen = $context->builder->load($siglenSlot);
        $finalLenI64 = $context->builder->zExt($finalLen, $i64);
        $result = $context->builder->call($context->lookupFunction('__string__alloc'), $finalLenI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($finalLenI64, $context->builder->structGep($result, $strMap['length']));
        $dest = $context->builder->structGep($result, $strMap['value']);
        $context->intrinsic->memcpy($dest, $sigBuf, $finalLen, false);
        $context->builder->call($context->lookupFunction('free'), $sigBuf);
        $context->builder->returnValue($result);
    }

    private static function emitVerify(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_verify_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $signature = $fn->getParam(1);
        $pubKeyPem = $fn->getParam(2);
        $algo = $fn->getParam(3);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullImpl = $i8p->constNull();
        $minusOne = $i32->constInt(-1, true);

        $digestCstr = self::resolveDigestCstr($context, $fn, $algo, 'ossl_verify');
        $failDigest = $fn->appendBasicBlock('ossl_verify_fail_digest');
        $haveDigest = $fn->appendBasicBlock('ossl_verify_have_digest');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $digestCstr, $i8p->constNull()),
            $failDigest,
            $haveDigest
        );
        $context->builder->positionAtEnd($failDigest);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($haveDigest);
        $md = $context->builder->call($context->lookupFunction('EVP_get_digestbyname'), $digestCstr);
        $context->builder->call($context->lookupFunction('free'), $digestCstr);

        $failMd = $fn->appendBasicBlock('ossl_verify_fail_md');
        $haveMd = $fn->appendBasicBlock('ossl_verify_have_md');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $md, $i8p->constNull()),
            $failMd,
            $haveMd
        );
        $context->builder->positionAtEnd($failMd);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($haveMd);
        $pkey = self::readPublicKey($context, $fn, $pubKeyPem, 'ossl_verify');
        $failKey = $fn->appendBasicBlock('ossl_verify_fail_key');
        $haveKey = $fn->appendBasicBlock('ossl_verify_have_key');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkey, $i8p->constNull()),
            $failKey,
            $haveKey
        );
        $context->builder->positionAtEnd($failKey);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($haveKey);
        $ctx = $context->builder->call($context->lookupFunction('EVP_MD_CTX_new'));
        $failCtx = $fn->appendBasicBlock('ossl_verify_fail_ctx');
        $haveCtx = $fn->appendBasicBlock('ossl_verify_have_ctx');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ctx, $i8p->constNull()),
            $failCtx,
            $haveCtx
        );
        $context->builder->positionAtEnd($failCtx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($haveCtx);
        $okInit = $context->builder->call(
            $context->lookupFunction('EVP_DigestVerifyInit'),
            $ctx,
            $nullImpl,
            $md,
            $nullImpl,
            $pkey
        );
        $failInit = $fn->appendBasicBlock('ossl_verify_fail_init');
        $afterInit = $fn->appendBasicBlock('ossl_verify_after_init');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okInit, $i32->constInt(1, false)),
            $failInit,
            $afterInit
        );
        $context->builder->positionAtEnd($failInit);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($afterInit);
        $dataPtr = self::stringData($context, $data);
        $dataLen = $context->builder->truncOrBitCast(self::stringLenI64($context, $data), $sizeT);
        $okUpdate = $context->builder->call(
            $context->lookupFunction('EVP_DigestVerifyUpdate'),
            $ctx,
            $dataPtr,
            $dataLen
        );
        $failUpdate = $fn->appendBasicBlock('ossl_verify_fail_update');
        $afterUpdate = $fn->appendBasicBlock('ossl_verify_after_update');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $okUpdate, $i32->constInt(1, false)),
            $failUpdate,
            $afterUpdate
        );
        $context->builder->positionAtEnd($failUpdate);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($afterUpdate);
        $sigLenI64 = self::stringLenI64($context, $signature);
        $failEmpty = $fn->appendBasicBlock('ossl_verify_empty_sig');
        $haveSig = $fn->appendBasicBlock('ossl_verify_have_sig');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $sigLenI64, $i64->constInt(0, false)),
            $failEmpty,
            $haveSig
        );
        $context->builder->positionAtEnd($failEmpty);
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($haveSig);
        $sigPtr = self::stringData($context, $signature);
        $sigLen = $context->builder->truncOrBitCast($sigLenI64, $context->getTypeFromString('size_t'));
        $result = $context->builder->call(
            $context->lookupFunction('EVP_DigestVerifyFinal'),
            $ctx,
            $sigPtr,
            $sigLen
        );
        $context->builder->call($context->lookupFunction('EVP_MD_CTX_free'), $ctx);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
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

    private static function readPublicKey(Context $context, LlvmFunction $fn, Value $pemStr, string $prefix): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $nullImpl = $i8p->constNull();

        $pemCstr = self::stringToCstr($context, $pemStr);
        $pemLenI32 = $context->builder->truncOrBitCast(self::stringLenI64($context, $pemStr), $i32);
        $bio = $context->builder->call($context->lookupFunction('BIO_new_mem_buf'), $pemCstr, $pemLenI32);

        $done = $fn->appendBasicBlock($prefix.'_read_pub_done');
        $slot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $slot);

        $failBio = $fn->appendBasicBlock($prefix.'_fail_bio_pub');
        $haveBio = $fn->appendBasicBlock($prefix.'_have_bio_pub');
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
        $failPub = $fn->appendBasicBlock($prefix.'_try_priv');
        $havePub = $fn->appendBasicBlock($prefix.'_have_pub');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pub, $i8p->constNull()),
            $failPub,
            $havePub
        );

        $context->builder->positionAtEnd($havePub);
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($pub, $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($failPub);
        $priv = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio,
            $nullImpl,
            $nullImpl,
            $nullImpl
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($priv, $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($slot);
    }

    private static function resolveDigestCstr(Context $context, LlvmFunction $fn, Value $algoI64, string $prefix): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        /** @var array<int, string> $map */
        $map = [
            OpensslConstants::OPENSSL_ALGO_SHA1 => 'sha1',
            OpensslConstants::OPENSSL_ALGO_MD5 => 'md5',
            OpensslConstants::OPENSSL_ALGO_MD4 => 'md4',
            OpensslConstants::OPENSSL_ALGO_SHA224 => 'sha224',
            OpensslConstants::OPENSSL_ALGO_SHA256 => 'sha256',
            OpensslConstants::OPENSSL_ALGO_SHA384 => 'sha384',
            OpensslConstants::OPENSSL_ALGO_SHA512 => 'sha512',
            OpensslConstants::OPENSSL_ALGO_RMD160 => 'ripemd160',
        ];

        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock($prefix.'_digest_done');
        $slot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $slot);

        $checkBlocks = [];
        $caseBlocks = [];
        foreach ($map as $const => $name) {
            $checkBlocks[$const] = $fn->appendBasicBlock($prefix.'_digest_chk_'.$const);
            $caseBlocks[$const] = $fn->appendBasicBlock($prefix.'_digest_case_'.$const);
        }
        $default = $fn->appendBasicBlock($prefix.'_digest_default');

        $firstCheck = reset($checkBlocks);
        $context->builder->branch($firstCheck ?: $default);

        $keys = array_keys($map);
        foreach ($keys as $idx => $const) {
            $name = $map[$const];
            $context->builder->positionAtEnd($checkBlocks[$const]);
            $next = $keys[$idx + 1] ?? null;
            $match = $context->builder->icmp(Builder::INT_EQ, $algoI64, $i64->constInt($const, false));
            $context->builder->branchIf(
                $match,
                $caseBlocks[$const],
                null !== $next ? $checkBlocks[$next] : $default
            );

            $context->builder->positionAtEnd($caseBlocks[$const]);
            $cstr = $context->builder->pointerCast(
                $context->constantFromString($name),
                $charPtr
            );
            $heap = self::dupCstr($context, $cstr, \strlen($name));
            $context->builder->store($heap, $slot);
            $context->builder->branch($done);
        }

        $context->builder->positionAtEnd($default);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($slot);
    }

    private static function dupCstr(Context $context, Value $src, int $len): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $lenI64 = $i64->constInt($len, false);
        $bufLen = $context->builder->add($lenI64, $i64->constInt(1, false));
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($bufLen, $sizeT)
        );
        $context->intrinsic->memcpy($buf, $src, $context->builder->truncOrBitCast($lenI64, $sizeT), false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $lenI64));

        return $context->builder->pointerCast($buf, $i8p);
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
            'EVP_get_digestbyname' => [$i8p, false, [$i8p]],
            'EVP_MD_CTX_new' => [$i8p, false, []],
            'EVP_MD_CTX_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_DigestSignInit' => [$i32, false, [$i8p, $voidp, $i8p, $voidp, $i8p]],
            'EVP_DigestSignUpdate' => [$i32, false, [$i8p, $voidp, $sizeT]],
            'EVP_DigestSignFinal' => [$i32, false, [$i8p, $i8p, $sizeTp]],
            'EVP_DigestVerifyInit' => [$i32, false, [$i8p, $voidp, $i8p, $voidp, $i8p]],
            'EVP_DigestVerifyUpdate' => [$i32, false, [$i8p, $voidp, $sizeT]],
            'EVP_DigestVerifyFinal' => [$i32, false, [$i8p, $i8p, $sizeT]],
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
