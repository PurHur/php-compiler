<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\openssl\VmOpensslSignNative;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * libcrypto EVP NestedJIT leaf for HashCryptoJitHelper (#3357, #21026).
 *
 * Always-helper path ({@see StringHashCryptoPhp} + {@see JitVmHelperLink}) NestedJIT-compiles
 * HashCryptoJitHelper, which calls {@see phpc_hash_crypto_hash} Internals that invoke these
 * `__phpc_hc_evp_*` leaves (HashAlgos #20652 / Fpow #20664 shape). Not a thin-standalone ABI fork.
 *
 * Digest buffers must use {@see allocaI8Bytes()} / {@see arrayAlloca} — PHPLLVM
 * {@see \PHPLLVM\Builder::alloca()} takes only a Type; bare `alloca($i8, N)`
 * ignores N and emits a 1-byte frame (AOT hash always-raw, #19274).
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash) / HMAC / PBKDF2 / HKDF
 */
final class JitHashCryptoKernel
{
    private const MAX_DIGEST_BYTES = 64;

    public const EVP_HASH = '__phpc_hc_evp_hash';

    public const EVP_HMAC = '__phpc_hc_evp_hmac';

    public const EVP_PBKDF2 = '__phpc_hc_evp_pbkdf2';

    public const EVP_HKDF = '__phpc_hc_evp_hkdf';

    public static function available(): bool
    {
        return VmOpensslSignNative::available();
    }

    /** Emit EVP leaf functions for NestedJIT Internal::call (#21026). */
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
        // malloc/free after LibcExtern always-on drop (#32273).
        LibcExtern::ensureMallocFamily($context);
        self::ensureLibcrypto($context);

        self::implementIfMissing($context, self::EVP_HASH, 'hc_llvm_hash_entry', self::emitHash(...));
        self::implementIfMissing($context, self::EVP_HMAC, 'hc_llvm_hmac_entry', self::emitHmac(...));
        self::implementIfMissing($context, self::EVP_PBKDF2, 'hc_llvm_pbkdf2_entry', self::emitPbkdf2(...));
        self::implementIfMissing($context, self::EVP_HKDF, 'hc_llvm_hkdf_entry', self::emitHkdf(...));
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

        $fn = null !== $probe ? $probe : self::declareEvpLeaf($context, $abiName);
        $emit($context, $fn);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareEvpLeaf(Context $context, string $abiName): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = match ($abiName) {
            self::EVP_HASH => $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32),
            self::EVP_HMAC => $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i32),
            self::EVP_PBKDF2 => $context->context->functionType(
                $strPtr,
                false,
                $strPtr,
                $strPtr,
                $strPtr,
                $i64,
                $i64,
                $i32
            ),
            self::EVP_HKDF => $context->context->functionType(
                $strPtr,
                false,
                $strPtr,
                $strPtr,
                $i64,
                $strPtr,
                $strPtr
            ),
            default => throw new \LogicException('unknown EVP leaf '.$abiName),
        };

        return $context->module->addFunction($abiName, $ft);
    }

    private static function implementNullAbiIfMissing(Context $context, string $abiName, string $entryName): void
    {
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

        $fn = null !== $probe ? $probe : self::declareEvpLeaf($context, $abiName);
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementNullStubs(Context $context): void
    {
        foreach ([
            self::EVP_HASH,
            self::EVP_HMAC,
            self::EVP_PBKDF2,
            self::EVP_HKDF,
        ] as $abiName) {
            self::implementNullAbiIfMissing($context, $abiName, 'hc_llvm_null_stub');
        }
    }

    private static function emitHash(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_llvm_hash_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $raw = $fn->getParam(2);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();

        $algoCstr = self::stringToCstr($context, $algo, 'hc_hash_algo');
        $mdType = $context->builder->call(
            $context->lookupFunction('EVP_get_digestbyname'),
            $algoCstr
        );
        $fail = $fn->appendBasicBlock('hc_llvm_hash_fail');
        $body = $fn->appendBasicBlock('hc_llvm_hash_body');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $mdType,
                $context->getTypeFromString('int8*')->constNull()
            ),
            $fail,
            $body
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $i32 = $context->getTypeFromString('int32');
        $mdBuf = self::allocaI8Bytes($context, self::MAX_DIGEST_BYTES);
        $mdLenSlot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(self::MAX_DIGEST_BYTES, false), $mdLenSlot);
        $dataPtr = self::stringData($context, $data);
        $dataLen = self::stringLenSizeT($context, $data);
        $ok = $context->builder->call(
            $context->lookupFunction('EVP_Digest'),
            $dataPtr,
            $dataLen,
            $mdBuf,
            $mdLenSlot,
            $mdType,
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->call($context->lookupFunction('free'), $algoCstr);

        $failDigest = $fn->appendBasicBlock('hc_llvm_hash_digest_fail');
        $okDigest = $fn->appendBasicBlock('hc_llvm_hash_digest_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ok, $i32->constInt(0, false)),
            $failDigest,
            $okDigest
        );

        $context->builder->positionAtEnd($failDigest);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okDigest);
        $mdLen = $context->builder->load($mdLenSlot);
        self::formatDigest($context, $fn, $mdBuf, $mdLen, $raw);
    }

    private static function emitHmac(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_llvm_hmac_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $data = $fn->getParam(1);
        $key = $fn->getParam(2);
        $raw = $fn->getParam(3);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');

        $algoCstr = self::stringToCstr($context, $algo, 'hc_hmac_algo');
        $mdType = $context->builder->call(
            $context->lookupFunction('EVP_get_digestbyname'),
            $algoCstr
        );
        $fail = $fn->appendBasicBlock('hc_llvm_hmac_fail');
        $body = $fn->appendBasicBlock('hc_llvm_hmac_body');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $mdType,
                $context->getTypeFromString('int8*')->constNull()
            ),
            $fail,
            $body
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);

        $mdBuf = self::allocaI8Bytes($context, self::MAX_DIGEST_BYTES);
        $mdLenSlot = $context->builder->alloca($i32);
        $keyPtr = self::stringData($context, $key);
        $keyLen = self::stringLenI32($context, $key);
        $dataPtr = self::stringData($context, $data);
        $dataLen = self::stringLenSizeT($context, $data);
        $hmacResult = $context->builder->call(
            $context->lookupFunction('HMAC'),
            $mdType,
            $keyPtr,
            $keyLen,
            $dataPtr,
            $dataLen,
            $mdBuf,
            $mdLenSlot
        );
        $failDigest = $fn->appendBasicBlock('hc_llvm_hmac_digest_fail');
        $okDigest = $fn->appendBasicBlock('hc_llvm_hmac_digest_ok');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $hmacResult,
                $context->getTypeFromString('int8*')->constNull()
            ),
            $failDigest,
            $okDigest
        );

        $context->builder->positionAtEnd($failDigest);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okDigest);
        $mdLen = $context->builder->load($mdLenSlot);
        self::formatDigest($context, $fn, $mdBuf, $mdLen, $raw);
    }

    private static function emitPbkdf2(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_llvm_pbkdf2_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $password = $fn->getParam(1);
        $salt = $fn->getParam(2);
        $iterations64 = $fn->getParam(3);
        $length64 = $fn->getParam(4);
        $raw = $fn->getParam(5);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        $algoCstr = self::stringToCstr($context, $algo, 'hc_pbkdf2_algo');
        $mdType = $context->builder->call(
            $context->lookupFunction('EVP_get_digestbyname'),
            $algoCstr
        );
        $fail = $fn->appendBasicBlock('hc_llvm_pbkdf2_fail');
        $body = $fn->appendBasicBlock('hc_llvm_pbkdf2_body');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $mdType,
                $i8p->constNull()
            ),
            $fail,
            $body
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);

        $iterations = $context->builder->truncOrBitCast($iterations64, $i32);
        $defaultKeyLen = self::pbkdf2DefaultKeyLenFromAlgo($context, $fn, $algo);
        $lengthZero = $context->builder->icmp(Builder::INT_EQ, $length64, $i64->constInt(0, false));
        $useDigestLen = $fn->appendBasicBlock('hc_llvm_pbkdf2_keylen_digest');
        $useArgLen = $fn->appendBasicBlock('hc_llvm_pbkdf2_keylen_arg');
        $keylenReady = $fn->appendBasicBlock('hc_llvm_pbkdf2_keylen_ready');
        $context->builder->branchIf($lengthZero, $useDigestLen, $useArgLen);

        $context->builder->positionAtEnd($useDigestLen);
        $defaultKeyLenZero = $context->builder->icmp(Builder::INT_EQ, $defaultKeyLen, $i32->constInt(0, false));
        $failDefaultLen = $fn->appendBasicBlock('hc_llvm_pbkdf2_default_keylen_fail');
        $context->builder->branchIf($defaultKeyLenZero, $failDefaultLen, $keylenReady);

        $context->builder->positionAtEnd($failDefaultLen);
        $context->builder->returnValue($nullStr);

        // php-src ext/hash/hash.c PHP_FUNCTION(hash_pbkdf2): when binary=false,
        // $length is the returned hex string length — derive ceil(length/2) bytes (#27241).
        $context->builder->positionAtEnd($useArgLen);
        $rawNonZero = $context->builder->icmp(Builder::INT_NE, $raw, $i32->constInt(0, false));
        $argRawKeyLenBb = $fn->appendBasicBlock('hc_llvm_pbkdf2_keylen_arg_raw');
        $argHexKeyLenBb = $fn->appendBasicBlock('hc_llvm_pbkdf2_keylen_arg_hex');
        $context->builder->branchIf($rawNonZero, $argRawKeyLenBb, $argHexKeyLenBb);

        $context->builder->positionAtEnd($argRawKeyLenBb);
        $rawArgKeyLen = $context->builder->truncOrBitCast($length64, $i32);
        $context->builder->branch($keylenReady);

        $context->builder->positionAtEnd($argHexKeyLenBb);
        $lengthI32 = $context->builder->truncOrBitCast($length64, $i32);
        $hexArgKeyLen = $context->builder->lShr(
            $context->builder->add($lengthI32, $i32->constInt(1, false)),
            $i32->constInt(1, false)
        );
        $context->builder->branch($keylenReady);

        $context->builder->positionAtEnd($keylenReady);
        $keylenPhi = $context->builder->phi($i32);
        $keylenPhi->addIncoming($defaultKeyLen, $useDigestLen);
        $keylenPhi->addIncoming($rawArgKeyLen, $argRawKeyLenBb);
        $keylenPhi->addIncoming($hexArgKeyLen, $argHexKeyLenBb);

        $outBuf = $context->builder->arrayAlloca($i8, $keylenPhi);
        $passPtr = self::stringData($context, $password);
        $passLen = self::stringLenI32($context, $password);
        $saltPtr = self::stringData($context, $salt);
        $saltLen = self::stringLenI32($context, $salt);
        $ok = $context->builder->call(
            $context->lookupFunction('PKCS5_PBKDF2_HMAC'),
            $passPtr,
            $passLen,
            $saltPtr,
            $saltLen,
            $iterations,
            $mdType,
            $keylenPhi,
            $outBuf
        );

        $failDerive = $fn->appendBasicBlock('hc_llvm_pbkdf2_derive_fail');
        $okDerive = $fn->appendBasicBlock('hc_llvm_pbkdf2_derive_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ok, $i32->constInt(0, false)),
            $failDerive,
            $okDerive
        );

        $context->builder->positionAtEnd($failDerive);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okDerive);
        // Hex path: return exactly $length chars (truncates odd lengths; php-src ZSTR_VAL[length]=0).
        self::formatDigest($context, $fn, $outBuf, $keylenPhi, $raw, $lengthZero, $length64);
    }

    private static function emitHkdf(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hc_llvm_hkdf_entry');
        $context->builder->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $key = $fn->getParam(1);
        $length64 = $fn->getParam(2);
        $info = $fn->getParam(3);
        $salt = $fn->getParam(4);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        $algoCstr = self::stringToCstr($context, $algo, 'hc_hkdf_algo');
        $mdType = $context->builder->call(
            $context->lookupFunction('EVP_get_digestbyname'),
            $algoCstr
        );
        $fail = $fn->appendBasicBlock('hc_llvm_hkdf_fail');
        $body = $fn->appendBasicBlock('hc_llvm_hkdf_body');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $mdType,
                $i8p->constNull()
            ),
            $fail,
            $body
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($body);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);
        $hlenI32 = self::digestLenI32FromAlgo($context, $fn, $algo);
        $hlenZero = $context->builder->icmp(Builder::INT_EQ, $hlenI32, $i32->constInt(0, false));
        $failHlen = $fn->appendBasicBlock('hc_llvm_hkdf_hlen_fail');
        $afterHlen = $fn->appendBasicBlock('hc_llvm_hkdf_after_hlen');
        $context->builder->branchIf($hlenZero, $failHlen, $afterHlen);

        $context->builder->positionAtEnd($failHlen);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterHlen);
        $hlenI64 = $context->builder->zext($hlenI32, $i64);

        $lengthZero = $context->builder->icmp(Builder::INT_EQ, $length64, $i64->constInt(0, false));
        $useDigestLen = $fn->appendBasicBlock('hc_llvm_hkdf_okm_digest_len');
        $useArgLen = $fn->appendBasicBlock('hc_llvm_hkdf_okm_arg_len');
        $okmLenReady = $fn->appendBasicBlock('hc_llvm_hkdf_okm_len_ready');
        $context->builder->branchIf($lengthZero, $useDigestLen, $useArgLen);

        $context->builder->positionAtEnd($useDigestLen);
        $context->builder->branch($okmLenReady);

        $context->builder->positionAtEnd($useArgLen);
        $context->builder->branch($okmLenReady);

        $context->builder->positionAtEnd($okmLenReady);
        $okmLenPhi = $context->builder->phi($i64);
        $okmLenPhi->addIncoming($hlenI64, $useDigestLen);
        $okmLenPhi->addIncoming($length64, $useArgLen);

        $saltWork = self::allocaI8Bytes($context, self::MAX_DIGEST_BYTES);
        $saltUseLenSlot = $context->builder->alloca($i64);
        $saltLenI32 = self::stringLenI32($context, $salt);
        $saltEmpty = $context->builder->icmp(Builder::INT_EQ, $saltLenI32, $i32->constInt(0, false));
        $saltZeroFill = $fn->appendBasicBlock('hc_llvm_hkdf_salt_zero');
        $saltUseArg = $fn->appendBasicBlock('hc_llvm_hkdf_salt_arg');
        $saltReady = $fn->appendBasicBlock('hc_llvm_hkdf_salt_ready');
        $context->builder->branchIf($saltEmpty, $saltZeroFill, $saltUseArg);

        $context->builder->positionAtEnd($saltZeroFill);
        $context->intrinsic->memset(
            $context->builder->pointerCast($saltWork, $i8p),
            $i8->constInt(0, false),
            $context->builder->truncOrBitCast($hlenI64, $sizeT),
            false
        );
        $context->builder->store($hlenI64, $saltUseLenSlot);
        $context->builder->branch($saltReady);

        $context->builder->positionAtEnd($saltUseArg);
        $context->intrinsic->memcpy(
            $context->builder->pointerCast($saltWork, $i8p),
            self::stringData($context, $salt),
            self::stringLenSizeT($context, $salt),
            false
        );
        $context->builder->store(
            $context->builder->zext($saltLenI32, $i64),
            $saltUseLenSlot
        );
        $context->builder->branch($saltReady);

        $context->builder->positionAtEnd($saltReady);
        $saltPtr = $context->builder->pointerCast($saltWork, $i8p);
        $saltLenUse = $context->builder->load($saltUseLenSlot);

        $prkBuf = self::allocaI8Bytes($context, self::MAX_DIGEST_BYTES);
        $prkLenSlot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(self::MAX_DIGEST_BYTES, false), $prkLenSlot);
        $keyPtr = self::stringData($context, $key);
        $hmacResult = $context->builder->call(
            $context->lookupFunction('HMAC'),
            $mdType,
            $saltPtr,
            $context->builder->truncOrBitCast($saltLenUse, $i32),
            $keyPtr,
            self::stringLenSizeT($context, $key),
            $prkBuf,
            $prkLenSlot
        );
        $failExtract = $fn->appendBasicBlock('hc_llvm_hkdf_extract_fail');
        $expand = $fn->appendBasicBlock('hc_llvm_hkdf_expand');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $hmacResult, $i8p->constNull()),
            $failExtract,
            $expand
        );

        $context->builder->positionAtEnd($failExtract);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($expand);
        $prkLenI32 = $context->builder->load($prkLenSlot);
        $prkLenI64 = $context->builder->zext($prkLenI32, $i64);
        $infoLenI64 = $context->builder->zext(self::stringLenI32($context, $info), $i64);
        $infoPtr = self::stringData($context, $info);
        $oneI64 = $i64->constInt(1, false);
        $maxInputLen = $context->builder->add(
            $context->builder->add($i64->constInt(self::MAX_DIGEST_BYTES, false), $infoLenI64),
            $oneI64
        );
        $okmBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($okmLenPhi, $sizeT)
        );
        $okmOut = $context->builder->pointerCast($okmBuf, $i8p);
        $inputBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($maxInputLen, $sizeT)
        );
        $tBuf = self::allocaI8Bytes($context, self::MAX_DIGEST_BYTES);
        $tLenSlot = $context->builder->alloca($i64);
        $okmPosSlot = $context->builder->alloca($i64);
        $blockSlot = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(0, false), $tLenSlot);
        $context->builder->store($i64->constInt(0, false), $okmPosSlot);
        $context->builder->store($i64->constInt(1, false), $blockSlot);

        $loopHead = $fn->appendBasicBlock('hc_llvm_hkdf_expand_head');
        $loopBody = $fn->appendBasicBlock('hc_llvm_hkdf_expand_body');
        $loopDone = $fn->appendBasicBlock('hc_llvm_hkdf_expand_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $okmPos = $context->builder->load($okmPosSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $okmPos, $okmLenPhi);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $tLen = $context->builder->load($tLenSlot);
        $blockNum = $context->builder->load($blockSlot);
        $inputLen = $context->builder->add($context->builder->add($tLen, $infoLenI64), $oneI64);
        $context->intrinsic->memset($inputBuf, $i8->constInt(0, false), $context->builder->truncOrBitCast($maxInputLen, $sizeT), false);
        $tLenNonZero = $context->builder->icmp(Builder::INT_NE, $tLen, $i64->constInt(0, false));
        $copyT = $fn->appendBasicBlock('hc_llvm_hkdf_copy_t');
        $afterCopyT = $fn->appendBasicBlock('hc_llvm_hkdf_after_copy_t');
        $context->builder->branchIf($tLenNonZero, $copyT, $afterCopyT);
        $context->builder->positionAtEnd($copyT);
        $context->intrinsic->memcpy(
            $inputBuf,
            $context->builder->pointerCast($tBuf, $i8p),
            $context->builder->truncOrBitCast($tLen, $sizeT),
            false
        );
        $context->builder->branch($afterCopyT);

        $context->builder->positionAtEnd($afterCopyT);
        $inputPos = $context->builder->select($tLenNonZero, $tLen, $i64->constInt(0, false));
        $infoDest = $context->builder->gep($inputBuf, $inputPos);
        $context->intrinsic->memcpy(
            $infoDest,
            $context->builder->pointerCast($infoPtr, $i8p),
            $context->builder->truncOrBitCast($infoLenI64, $sizeT),
            false
        );
        $counterPos = $context->builder->add($inputPos, $infoLenI64);
        $counterPtr = $context->builder->gep($inputBuf, $counterPos);
        $context->builder->store(
            $context->builder->truncOrBitCast($blockNum, $i8),
            $counterPtr
        );

        $tLenOutSlot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(self::MAX_DIGEST_BYTES, false), $tLenOutSlot);
        $expandResult = $context->builder->call(
            $context->lookupFunction('HMAC'),
            $mdType,
            $prkBuf,
            $context->builder->truncOrBitCast($prkLenI64, $i32),
            $inputBuf,
            $context->builder->truncOrBitCast($inputLen, $sizeT),
            $tBuf,
            $tLenOutSlot
        );
        $failExpand = $fn->appendBasicBlock('hc_llvm_hkdf_expand_fail');
        $afterExpand = $fn->appendBasicBlock('hc_llvm_hkdf_after_expand');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $expandResult, $i8p->constNull()),
            $failExpand,
            $afterExpand
        );

        $context->builder->positionAtEnd($failExpand);
        $context->builder->call($context->lookupFunction('free'), $inputBuf);
        $context->builder->call($context->lookupFunction('free'), $okmBuf);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($afterExpand);
        $newTLenI32 = $context->builder->load($tLenOutSlot);
        $newTLenI64 = $context->builder->zext($newTLenI32, $i64);
        $context->builder->store($newTLenI64, $tLenSlot);
        $remaining = $context->builder->sub($okmLenPhi, $okmPos);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $newTLenI64, $remaining),
            $newTLenI64,
            $remaining
        );
        $context->intrinsic->memcpy(
            $context->builder->gep($okmOut, $okmPos),
            $context->builder->pointerCast($tBuf, $i8p),
            $context->builder->truncOrBitCast($copyLen, $sizeT),
            false
        );
        $context->builder->store($context->builder->add($okmPos, $copyLen), $okmPosSlot);
        $context->builder->store($context->builder->add($blockNum, $oneI64), $blockSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call($context->lookupFunction('free'), $inputBuf);
        $strMap = $context->structFieldMap['__string__'];
        $rawResult = $context->builder->call($context->lookupFunction('__string__alloc'), $okmLenPhi);
        $context->builder->store($okmLenPhi, $context->builder->structGep($rawResult, $strMap['length']));
        $destPtr = $context->builder->structGep($rawResult, $strMap['value']);
        $context->intrinsic->memcpy(
            $destPtr,
            $context->builder->pointerCast($okmOut, $i8p),
            $context->builder->truncOrBitCast($okmLenPhi, $sizeT),
            false
        );
        $context->builder->call($context->lookupFunction('free'), $okmBuf);
        $context->builder->returnValue($rawResult);
    }

    private static function digestLenI32FromAlgo(Context $context, LlvmFunction $fn, Value $algo): Value
    {
        return self::pbkdf2DefaultKeyLenFromAlgo($context, $fn, $algo);
    }

    private static function pbkdf2DefaultKeyLenFromAlgo(Context $context, LlvmFunction $fn, Value $algo): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $slot = $context->builder->alloca($i32);
        $context->builder->store($i32->constInt(0, false), $slot);

        $algoCstr = self::stringToCstr($context, $algo, 'hc_pbkdf2_keylen_algo');
        $sha256 = $context->pointerFromStringConstant('sha256');
        $sha1 = $context->pointerFromStringConstant('sha1');
        $md5 = $context->pointerFromStringConstant('md5');
        $done = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_done');

        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
        $isSha256 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $algoCstr, $sha256),
            $i32->constInt(0, false)
        );
        $sha256Bb = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_sha256');
        $checkSha1 = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_sha1');
        $context->builder->branchIf($isSha256, $sha256Bb, $checkSha1);

        $context->builder->positionAtEnd($sha256Bb);
        $context->builder->store($i32->constInt(32, false), $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkSha1);
        $isSha1 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $algoCstr, $sha1),
            $i32->constInt(0, false)
        );
        $sha1Bb = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_sha1');
        $checkMd5 = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_md5');
        $context->builder->branchIf($isSha1, $sha1Bb, $checkMd5);

        $context->builder->positionAtEnd($sha1Bb);
        $context->builder->store($i32->constInt(20, false), $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkMd5);
        $isMd5 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $algoCstr, $md5),
            $i32->constInt(0, false)
        );
        $md5Bb = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_md5');
        $context->builder->branchIf($isMd5, $md5Bb, $done);

        $context->builder->positionAtEnd($md5Bb);
        $context->builder->store($i32->constInt(16, false), $slot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->call($context->lookupFunction('free'), $algoCstr);

        return $context->builder->load($slot);
    }

    /**
     * @param Value|null $lengthZero   when set with $requestedLen: hex output uses $requestedLen
     *                                 chars if length was non-zero (hash_pbkdf2; #27241)
     * @param Value|null $requestedLen i64 caller-facing length (hex char count when !raw)
     */
    private static function formatDigest(
        Context $context,
        LlvmFunction $fn,
        Value $mdBuf,
        Value $mdLen,
        Value $raw,
        ?Value $lengthZero = null,
        ?Value $requestedLen = null
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $rawNonZero = $context->builder->icmp(Builder::INT_NE, $raw, $i32->constInt(0, false));
        $rawBb = $fn->appendBasicBlock('hc_llvm_digest_raw');
        $hexBb = $fn->appendBasicBlock('hc_llvm_digest_hex');
        $context->builder->branchIf($rawNonZero, $rawBb, $hexBb);

        $context->builder->positionAtEnd($rawBb);
        $mdLenI64 = $context->builder->zExt($mdLen, $i64);
        $rawStr = $context->builder->call($context->lookupFunction('__string__alloc'), $mdLenI64);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($mdLenI64, $context->builder->structGep($rawStr, $strMap['length']));
        $destPtr = $context->builder->structGep($rawStr, $strMap['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $context->intrinsic->memcpy(
            $destPtr,
            $context->builder->pointerCast($mdBuf, $i8p),
            $context->builder->truncOrBitCast($mdLenI64, $sizeT),
            false
        );
        $context->builder->returnValue($rawStr);

        $context->builder->positionAtEnd($hexBb);
        $mdLenI64 = $context->builder->zExt($mdLen, $i64);
        $fullHexLen = $context->builder->mul($mdLenI64, $i64->constInt(2, false));
        if (null !== $lengthZero && null !== $requestedLen) {
            // length==0 → full hex of digest_size bytes; else exactly $requestedLen chars.
            $hexLen = $context->builder->select($lengthZero, $fullHexLen, $requestedLen);
        } else {
            $hexLen = $fullHexLen;
        }
        $hexStr = $context->builder->call($context->lookupFunction('__string__alloc'), $hexLen);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($hexLen, $context->builder->structGep($hexStr, $strMap['length']));
        $destPtr = $context->builder->structGep($hexStr, $strMap['value']);
        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789abcdef'),
            $charPtr
        );

        $idxSlot = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loopHead = $fn->appendBasicBlock('hc_llvm_hex_head');
        $loopBody = $fn->appendBasicBlock('hc_llvm_hex_body');
        $loopDone = $fn->appendBasicBlock('hc_llvm_hex_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $pastDigest = $context->builder->icmp(Builder::INT_SGE, $idx, $mdLenI64);
        $outPosProbe = $context->builder->mulNoSignedWrap(
            $context->builder->truncOrBitCast($idx, $i32),
            $i32->constInt(2, false)
        );
        $pastHex = $context->builder->icmp(
            Builder::INT_SGE,
            $context->builder->zExt($outPosProbe, $i64),
            $hexLen
        );
        $stop = $context->builder->bitwiseOr($pastDigest, $pastHex);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $byte = $context->builder->load($context->builder->gep($mdBuf, $idx));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));
        $outPos = $context->builder->mulNoSignedWrap($idxI32, $i32->constInt(2, false));
        $outPosI64 = $context->builder->zExt($outPos, $i64);
        $hiInRange = $context->builder->icmp(Builder::INT_SLT, $outPosI64, $hexLen);
        $writeHiBb = $fn->appendBasicBlock('hc_llvm_hex_write_hi');
        $afterHiBb = $fn->appendBasicBlock('hc_llvm_hex_after_hi');
        $context->builder->branchIf($hiInRange, $writeHiBb, $afterHiBb);

        $context->builder->positionAtEnd($writeHiBb);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->branch($afterHiBb);

        $context->builder->positionAtEnd($afterHiBb);
        $loPos = $context->builder->add($outPos, $i32->constInt(1, false));
        $loPosI64 = $context->builder->zExt($loPos, $i64);
        $loInRange = $context->builder->icmp(Builder::INT_SLT, $loPosI64, $hexLen);
        $writeLoBb = $fn->appendBasicBlock('hc_llvm_hex_write_lo');
        $afterLoBb = $fn->appendBasicBlock('hc_llvm_hex_after_lo');
        $context->builder->branchIf($loInRange, $writeLoBb, $afterLoBb);

        $context->builder->positionAtEnd($writeLoBb);
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $loPos)
        );
        $context->builder->branch($afterLoBb);

        $context->builder->positionAtEnd($afterLoBb);
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($hexStr);
    }

    /**
     * Fixed-size i8 stack buffer as i8* (#19274).
     *
     * PHPLLVM {@see \PHPLLVM\Builder::alloca()} allocates a single `Type` —
     * use {@see Type::arrayType()} then pointerCast so digests are not 1-byte frames.
     */
    private static function allocaI8Bytes(Context $context, int $nbytes): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $buf = $context->builder->alloca($i8->arrayType($nbytes));

        return $context->builder->pointerCast($buf, $i8p);
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function stringLenSizeT(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));

        return $context->builder->truncOrBitCast($len, $context->getTypeFromString('size_t'));
    }

    private static function stringLenI32(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));

        return $context->builder->truncOrBitCast($len, $context->getTypeFromString('int32'));
    }

    private static function stringToCstr(Context $context, Value $strPtr, string $prefix): Value
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
        $buf = $context->builder->call($context->lookupFunction('malloc'), $context->builder->truncOrBitCast($bufLen, $sizeT));
        $cstr = $context->builder->pointerCast($buf, $i8p);
        $context->intrinsic->memcpy($cstr, $bytes, $context->builder->truncOrBitCast($len, $sizeT), false);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($cstr, $len));

        return $cstr;
    }

    private static function ensureLibcrypto(Context $context): void
    {
        $ctx = $context->context;
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32p = $i32->pointerType(0);

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'EVP_get_digestbyname' => [$i8p, false, [$i8p]],
            'EVP_Digest' => [$i32, false, [$i8p, $sizeT, $i8p, $i32p, $i8p, $i8p]],
            'HMAC' => [$i8p, false, [$i8p, $i8p, $i32, $i8p, $sizeT, $i8p, $i32p]],
            'PKCS5_PBKDF2_HMAC' => [$i32, false, [$i8p, $i32, $i8p, $i32, $i32, $i8p, $i32, $i8p]],
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
