<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\openssl\VmOpensslSignNative;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin libcrypto EVP bridge for user-script standalone AOT (#3357, #16734).
 *
 * Nested HashCryptoJitHelper does not run reliably in minimal standalone init
 * (helper unit __init__ skipped under PHP_COMPILER_AOT_USER_SCRIPT). OpenSSL
 * EVP_Digest/HMAC match php-src ext/hash without nested PHP lowering.
 * php-src: ext/standard/hash.c, ext/hash/hash.c
 */
final class StringHashCryptoLlvm
{
    private const MAX_DIGEST_BYTES = 64;

    public static function available(): bool
    {
        return VmOpensslSignNative::available();
    }

    public static function implement(Context $context): void
    {
        if (!self::available()) {
            self::implementNullStubs($context);

            return;
        }

        LibcExtern::register($context);
        self::ensureLibcrypto($context);

        self::implementIfMissing($context, '__compiler_hash', 'hc_llvm_hash_entry', self::emitHash(...));
        self::implementIfMissing($context, '__compiler_hash_hmac', 'hc_llvm_hmac_entry', self::emitHmac(...));
        self::implementIfMissing($context, '__compiler_hash_pbkdf2', 'hc_llvm_pbkdf2_entry', self::emitPbkdf2(...));
        self::implementIfMissing($context, '__compiler_hash_hkdf', 'hc_llvm_hkdf_entry', self::emitHkdf(...));
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

        $fn = null !== $probe ? $probe : $context->lookupFunction($abiName);
        $emit($context, $fn);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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

        $fn = null !== $probe ? $probe : $context->lookupFunction($abiName);
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
            '__compiler_hash',
            '__compiler_hash_hmac',
            '__compiler_hash_pbkdf2',
            '__compiler_hash_hkdf',
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
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $mdBuf = $context->builder->alloca($i8, self::MAX_DIGEST_BYTES, 'hc_hash_md');
        $mdLenSlot = $context->builder->alloca($i32, 1, 'hc_hash_md_len');
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

        $mdBuf = $context->builder->alloca($i8, self::MAX_DIGEST_BYTES, 'hc_hmac_md');
        $mdLenSlot = $context->builder->alloca($i32, 1, 'hc_hmac_md_len');
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

        $context->builder->positionAtEnd($useArgLen);
        $argKeyLen = $context->builder->truncOrBitCast($length64, $i32);
        $context->builder->branch($keylenReady);

        $context->builder->positionAtEnd($keylenReady);
        $keylenPhi = $context->builder->phi($i32);
        $keylenPhi->addIncoming($defaultKeyLen, $useDigestLen);
        $keylenPhi->addIncoming($argKeyLen, $useArgLen);

        $outBuf = $context->builder->alloca($i8, $keylenPhi, 'hc_pbkdf2_out');
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
        self::formatDigest($context, $fn, $outBuf, $keylenPhi, $raw);
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
        $hlen = $context->builder->call($context->lookupFunction('EVP_MD_get_size'), $mdType);
        $hlenI64 = $context->builder->zExt($hlen, $i64);

        $lengthNeg = $context->builder->icmp(Builder::INT_SLT, $length64, $i64->constInt(0, false));
        $emptyOut = $fn->appendBasicBlock('hc_llvm_hkdf_empty');
        $lengthOk = $fn->appendBasicBlock('hc_llvm_hkdf_length_ok');
        $context->builder->branchIf($lengthNeg, $emptyOut, $lengthOk);

        $context->builder->positionAtEnd($emptyOut);
        $context->builder->returnValue(self::emptyRawString($context));

        $context->builder->positionAtEnd($lengthOk);
        $lengthZero = $context->builder->icmp(Builder::INT_EQ, $length64, $i64->constInt(0, false));
        $useDigestLen = $fn->appendBasicBlock('hc_llvm_hkdf_okm_digest');
        $useArgLen = $fn->appendBasicBlock('hc_llvm_hkdf_okm_arg');
        $okmReady = $fn->appendBasicBlock('hc_llvm_hkdf_okm_ready');
        $context->builder->branchIf($lengthZero, $useDigestLen, $useArgLen);

        $context->builder->positionAtEnd($useDigestLen);
        $context->builder->branch($okmReady);

        $context->builder->positionAtEnd($useArgLen);
        $context->builder->branch($okmReady);

        $context->builder->positionAtEnd($okmReady);
        $okmLenPhi = $context->builder->phi($i64);
        $okmLenPhi->addIncoming($hlenI64, $useDigestLen);
        $okmLenPhi->addIncoming($length64, $useArgLen);

        $okmZero = $context->builder->icmp(Builder::INT_EQ, $okmLenPhi, $i64->constInt(0, false));
        $emptyOkm = $fn->appendBasicBlock('hc_llvm_hkdf_okm_empty');
        $extract = $fn->appendBasicBlock('hc_llvm_hkdf_extract');
        $context->builder->branchIf($okmZero, $emptyOkm, $extract);

        $context->builder->positionAtEnd($emptyOkm);
        $context->builder->returnValue(self::emptyRawString($context));

        $context->builder->positionAtEnd($extract);
        $saltStr = self::hkdfSaltString($context, $fn, $salt, $hlen);
        $rawI32 = $i32->constInt(1, false);
        $prkStr = $context->builder->call(
            $context->lookupFunction('__compiler_hash_hmac'),
            $algo,
            $key,
            $saltStr,
            $rawI32
        );

        $result = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->builder->truncOrBitCast($okmLenPhi, $sizeT)
        );
        $strMap = $context->structFieldMap['__string__'];
        $destPtr = $context->builder->structGep($result, $strMap['value']);

        $tStrSlot = $context->builder->alloca($strPtr, 1, 'hc_hkdf_t');
        $context->builder->store(self::emptyRawString($context), $tStrSlot);
        $posSlot = $context->builder->alloca($i64, 1, 'hc_hkdf_pos');
        $context->builder->store($i64->constInt(0, false), $posSlot);
        $idxSlot = $context->builder->alloca($i64, 1, 'hc_hkdf_idx');
        $context->builder->store($i64->constInt(1, false), $idxSlot);

        $loopHead = $fn->appendBasicBlock('hc_llvm_hkdf_expand_head');
        $loopBody = $fn->appendBasicBlock('hc_llvm_hkdf_expand_body');
        $loopDone = $fn->appendBasicBlock('hc_llvm_hkdf_expand_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $pos, $okmLenPhi);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $tStr = $context->builder->load($tStrSlot);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $dataStr = self::concatStringStringByte($context, $tStr, $info, $idxI32);
        $tBlock = $context->builder->call(
            $context->lookupFunction('__compiler_hash_hmac'),
            $algo,
            $dataStr,
            $prkStr,
            $rawI32
        );
        $context->builder->store($tBlock, $tStrSlot);

        $tLen = $context->builder->load($context->builder->structGep($tBlock, $strMap['length']));
        $remaining = $context->builder->sub($okmLenPhi, $pos);
        $needMore = $context->builder->icmp(Builder::INT_SLT, $remaining, $tLen);
        $copyLen = $context->builder->select($needMore, $remaining, $tLen);
        $copyLenSizeT = $context->builder->truncOrBitCast($copyLen, $sizeT);
        $destAtPos = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($destPtr, $i8p),
            $context->builder->truncOrBitCast($pos, $i32)
        );
        $context->intrinsic->memcpy(
            $destAtPos,
            $context->builder->structGep($tBlock, $strMap['value']),
            $copyLenSizeT,
            false
        );
        $context->builder->store($context->builder->add($pos, $copyLen), $posSlot);
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($result);
    }

    private static function hkdfSaltString(
        Context $context,
        LlvmFunction $fn,
        Value $salt,
        Value $hlen
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $charPtr = $context->getTypeFromString('char*');
        $saltLen = self::stringLenI32($context, $salt);
        $saltEmpty = $context->builder->icmp(Builder::INT_EQ, $saltLen, $i32->constInt(0, false));
        $useZero = $fn->appendBasicBlock('hc_hkdf_salt_zero');
        $useSalt = $fn->appendBasicBlock('hc_hkdf_salt_arg');
        $saltReady = $fn->appendBasicBlock('hc_hkdf_salt_ready');
        $context->builder->branchIf($saltEmpty, $useZero, $useSalt);

        $context->builder->positionAtEnd($useZero);
        $zeroBuf = $context->builder->alloca($i8, self::MAX_DIGEST_BYTES, 'hc_hkdf_zero_salt');
        $context->intrinsic->memset(
            $zeroBuf,
            $i8->constInt(0, false),
            $context->builder->zExtOrBitCast($hlen, $context->getTypeFromString('size_t')),
            false
        );
        $hlenI64 = $context->builder->zExt($hlen, $i64);
        $zeroSalt = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $hlenI64,
            $context->builder->pointerCast($zeroBuf, $charPtr)
        );
        $context->builder->branch($saltReady);

        $context->builder->positionAtEnd($useSalt);
        $context->builder->branch($saltReady);

        $context->builder->positionAtEnd($saltReady);
        $saltPhi = $context->builder->phi($strPtr);
        $saltPhi->addIncoming($zeroSalt, $useZero);
        $saltPhi->addIncoming($salt, $useSalt);

        return $saltPhi;
    }

    private static function emptyRawString(Context $context): Value
    {
        $sizeT = $context->getTypeFromString('size_t');

        return $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $sizeT->constInt(0, false)
        );
    }

    private static function concatStringStringByte(
        Context $context,
        Value $left,
        Value $right,
        Value $byteI32
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];

        $leftLen = $context->builder->load($context->builder->structGep($left, $strMap['length']));
        $rightLen = $context->builder->load($context->builder->structGep($right, $strMap['length']));
        $one = $i64->constInt(1, false);
        $totalLen = $context->builder->add($context->builder->add($leftLen, $rightLen), $one);
        $dest = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->builder->truncOrBitCast($totalLen, $sizeT)
        );
        $destPtr = $context->builder->structGep($dest, $strMap['value']);
        $leftPtr = $context->builder->structGep($left, $strMap['value']);
        $rightPtr = $context->builder->structGep($right, $strMap['value']);
        $leftLenSizeT = $context->builder->truncOrBitCast($leftLen, $sizeT);
        $rightLenSizeT = $context->builder->truncOrBitCast($rightLen, $sizeT);
        $context->intrinsic->memcpy($destPtr, $leftPtr, $leftLenSizeT, false);
        $destAfterLeft = $context->builder->inBoundsGEP(
            $context->builder->pointerCast($destPtr, $context->getTypeFromString('int8*')),
            $context->builder->truncOrBitCast($leftLen, $i32)
        );
        $context->intrinsic->memcpy($destAfterLeft, $rightPtr, $rightLenSizeT, false);
        $bytePtr = $context->builder->inBoundsGEP(
            $destAfterLeft,
            $context->builder->truncOrBitCast($rightLen, $i32)
        );
        $context->builder->store(
            $context->builder->truncOrBitCast($byteI32, $i8),
            $bytePtr
        );

        return $dest;
    }

    private static function pbkdf2DefaultKeyLenFromAlgo(Context $context, LlvmFunction $fn, Value $algo): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $slot = $context->builder->alloca($i32, 1, 'hc_pbkdf2_default_keylen');
        $context->builder->store($i32->constInt(0, false), $slot);

        $algoCstr = self::stringToCstr($context, $algo, 'hc_pbkdf2_keylen_algo');
        $sha256 = $context->pointerFromStringConstant('sha256');
        $sha1 = $context->pointerFromStringConstant('sha1');
        $md5 = $context->pointerFromStringConstant('md5');
        $done = $fn->appendBasicBlock('hc_pbkdf2_keylen_pick_done');

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

    private static function formatDigest(
        Context $context,
        LlvmFunction $fn,
        Value $mdBuf,
        Value $mdLen,
        Value $raw
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
        $rawResult = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $mdLenI64,
            $context->builder->pointerCast($mdBuf, $charPtr)
        );
        $context->builder->returnValue($rawResult);

        $context->builder->positionAtEnd($hexBb);
        $mdLenI64 = $context->builder->zExt($mdLen, $i64);
        $hexLen = $context->builder->mul($mdLenI64, $i64->constInt(2, false));
        $hexStr = $context->builder->call($context->lookupFunction('__string__alloc'), $hexLen);
        $strMap = $context->structFieldMap['__string__'];
        $context->builder->store($hexLen, $context->builder->structGep($hexStr, $strMap['length']));
        $destPtr = $context->builder->structGep($hexStr, $strMap['value']);
        $hexTable = $context->builder->pointerCast(
            $context->constantFromString('0123456789abcdef'),
            $charPtr
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'hc_hex_idx');
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loopHead = $fn->appendBasicBlock('hc_llvm_hex_head');
        $loopBody = $fn->appendBasicBlock('hc_llvm_hex_body');
        $loopDone = $fn->appendBasicBlock('hc_llvm_hex_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $mdLenI64);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxI32 = $context->builder->truncOrBitCast($idx, $i32);
        $byte = $context->builder->load($context->builder->gep($mdBuf, $idx));
        $byteI32 = $context->builder->zExt($byte, $i32);
        $hi = $context->builder->lShr($byteI32, $i32->constInt(4, false));
        $lo = $context->builder->bitwiseAnd($byteI32, $i32->constInt(0x0F, false));
        $outPos = $context->builder->mulNoSignedWrap($idxI32, $i32->constInt(2, false));
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($destPtr, $context->builder->add($outPos, $i32->constInt(1, false)))
        );
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($hexStr);
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
            'EVP_MD_get_size' => [$i32, false, [$i8p]],
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
