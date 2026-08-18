<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder as LlvmBuilder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT openssl_pbkdf2() via HMAC built from {@see __compiler_hash} (#32410).
 *
 * hash() / openssl_digest AOT is green; hash_hmac()/hash_pbkdf2() HashCrypto (HMAC /
 * PKCS5_PBKDF2_HMAC) SIGSEGV under AOT. NestedJIT of VmHashNative::hashPbkdf2 also
 * SIGSEGVs (#16075). This kernel uses only __compiler_hash + LLVM loops.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pbkdf2) / PKCS5_PBKDF2_HMAC
 */
final class OpensslPbkdf2Runtime
{
    private const HMAC_ABI = '__phpc_ossl_hmac';

    private const HEX2BIN_ABI = '__phpc_ossl_hex2bin';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_openssl_pbkdf2',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_openssl_pbkdf2');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringHashCrypto::ensureLinked($context);
        self::ensureHex2binFunction($context);
        self::ensureHmacFunction($context);
        self::implementIfMissing($context, '__compiler_openssl_pbkdf2', self::emitPbkdf2(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureHex2binFunction(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::HEX2BIN_ABI);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::HEX2BIN_ABI, $existing);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $ty = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $existing ?? $context->module->addFunction(self::HEX2BIN_ABI, $ty);
        self::emitHex2bin($context, $fn);
        $context->registerFunction(self::HEX2BIN_ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    /** Decode hash() hex output to raw bytes — AOT raw __compiler_hash SIGSEGVs. */
    private static function emitHex2bin(Context $context, LlvmFunction $fn): void
    {
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('ossl_hex2bin_entry');
        $b->positionAtEnd($entry);
        $hex = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = self::stringLenI64($context, $hex);
        $outLen = $b->unsignedDiv($len, $i64->constInt(2, false));
        $buf = self::allocaI8Bytes($context, 64);
        $src = $b->pointerCast(self::stringData($context, $hex), $i8p);
        $iSlot = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $iSlot);
        $head = $fn->appendBasicBlock('ossl_hex2bin_h');
        $body = $fn->appendBasicBlock('ossl_hex2bin_b');
        $done = $fn->appendBasicBlock('ossl_hex2bin_d');
        $b->branch($head);
        $b->positionAtEnd($head);
        $i = $b->load($iSlot);
        $b->branchIf($b->icmp(LlvmBuilder::INT_ULT, $i, $outLen), $body, $done);
        $b->positionAtEnd($body);
        $twoI = $b->add($i, $i);
        $c0 = $b->zExt($b->load($b->gep($src, $twoI)), $i32);
        $c1 = $b->zExt($b->load($b->gep($src, $b->add($twoI, $i64->constInt(1, false)))), $i32);
        $hi = self::emitHexNibbleI32($context, $c0);
        $lo = self::emitHexNibbleI32($context, $c1);
        $byte = $b->trunc(
            $b->bitwiseOr($b->shl($hi, $i32->constInt(4, false)), $lo),
            $i8
        );
        $b->store($byte, $b->gep($buf, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $iSlot);
        $b->branch($head);
        $b->positionAtEnd($done);
        $b->returnValue($b->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $b->pointerCast($buf, $charPtr)
        ));
    }

    /** @param Value $chI32 zero-extended hex digit */
    private static function emitHexNibbleI32(Context $context, Value $chI32): Value
    {
        $b = $context->builder;
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(\ord('0'), false);
        $nine = $i32->constInt(9, false);
        $a = $i32->constInt(\ord('a'), false);
        $A = $i32->constInt(\ord('A'), false);
        $fromDigit = $b->sub($chI32, $zero);
        $isDigit = $b->icmp(LlvmBuilder::INT_ULE, $fromDigit, $nine);
        $fromLower = $b->add($b->sub($chI32, $a), $i32->constInt(10, false));
        $fromUpper = $b->add($b->sub($chI32, $A), $i32->constInt(10, false));
        $isLower = $b->icmp(LlvmBuilder::INT_UGE, $chI32, $a);

        return $b->select($isDigit, $fromDigit, $b->select($isLower, $fromLower, $fromUpper));
    }

    private static function ensureHmacFunction(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::HMAC_ABI);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::HMAC_ABI, $existing);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $ty = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fn = $existing ?? $context->module->addFunction(self::HMAC_ABI, $ty);
        self::emitHmac($context, $fn);
        $context->registerFunction(self::HMAC_ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    /** HMAC-SHA1/256/MD5 via hex {@see __compiler_hash} + LLVM hex-decode (raw hash AOT SIGSEGVs). */
    private static function emitHmac(Context $context, LlvmFunction $fn): void
    {
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('ossl_hmac_entry');
        $b->positionAtEnd($entry);

        $algo = $fn->getParam(0);
        $key = $fn->getParam(1);
        $data = $fn->getParam(2);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $hexI32 = $i32->constInt(0, false);
        $block = $i64->constInt(64, false);

        $keyLen = self::stringLenI64($context, $key);
        $needHash = $b->icmp(LlvmBuilder::INT_UGT, $keyLen, $block);
        $hashKey = $fn->appendBasicBlock('ossl_hmac_hash_key');
        $keyReady = $fn->appendBasicBlock('ossl_hmac_key_ready');
        $b->branchIf($needHash, $hashKey, $keyReady);

        $b->positionAtEnd($hashKey);
        $hashedKeyHex = $b->call($context->lookupFunction('__compiler_hash'), $algo, $key, $hexI32);
        $hashFail = $fn->appendBasicBlock('ossl_hmac_hash_key_fail');
        $hashOk = $fn->appendBasicBlock('ossl_hmac_hash_key_ok');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $hashedKeyHex, $nullStr), $hashFail, $hashOk);
        $b->positionAtEnd($hashFail);
        $b->returnValue($nullStr);
        $b->positionAtEnd($hashOk);
        $hashedKey = $b->call($context->lookupFunction(self::HEX2BIN_ABI), $hashedKeyHex);
        $b->branch($keyReady);

        $b->positionAtEnd($keyReady);
        $keyPhi = $b->phi($strPtr);
        $keyPhi->addIncoming($key, $entry);
        $keyPhi->addIncoming($hashedKey, $hashOk);

        $kpad = self::allocaI8Bytes($context, 64);
        self::zeroBytes($context, $fn, $kpad, $block, 'ossl_hmac_kpadz');
        $copyN = $b->select(
            $b->icmp(LlvmBuilder::INT_ULT, self::stringLenI64($context, $keyPhi), $block),
            self::stringLenI64($context, $keyPhi),
            $block
        );
        $context->intrinsic->builder = $b;
        $context->intrinsic->memcpy(
            $kpad,
            self::stringData($context, $keyPhi),
            $b->truncOrBitCast($copyN, $sizeT),
            false
        );

        $ipad = self::allocaI8Bytes($context, 64);
        $opad = self::allocaI8Bytes($context, 64);
        self::xorPad64($context, $fn, $kpad, $ipad, 0x36, 'ossl_hmac_ipad');
        self::xorPad64($context, $fn, $kpad, $opad, 0x5c, 'ossl_hmac_opad');

        $ipadStr = self::bytesToString($context, $ipad, $block);
        $innerMsg = self::concatStr($context, $ipadStr, $data);
        $innerHex = $b->call($context->lookupFunction('__compiler_hash'), $algo, $innerMsg, $hexI32);
        $innerFail = $fn->appendBasicBlock('ossl_hmac_inner_fail');
        $innerOk = $fn->appendBasicBlock('ossl_hmac_inner_ok');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $innerHex, $nullStr), $innerFail, $innerOk);
        $b->positionAtEnd($innerFail);
        $b->returnValue($nullStr);

        $b->positionAtEnd($innerOk);
        $inner = $b->call($context->lookupFunction(self::HEX2BIN_ABI), $innerHex);
        $opadStr = self::bytesToString($context, $opad, $block);
        $outerMsg = self::concatStr($context, $opadStr, $inner);
        $outerHex = $b->call($context->lookupFunction('__compiler_hash'), $algo, $outerMsg, $hexI32);
        $outerFail = $fn->appendBasicBlock('ossl_hmac_outer_fail');
        $outerOk = $fn->appendBasicBlock('ossl_hmac_outer_ok');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $outerHex, $nullStr), $outerFail, $outerOk);
        $b->positionAtEnd($outerFail);
        $b->returnValue($nullStr);
        $b->positionAtEnd($outerOk);
        $b->returnValue($b->call($context->lookupFunction(self::HEX2BIN_ABI), $outerHex));
    }

    private static function emitPbkdf2(Context $context, LlvmFunction $fn): void
    {
        $b = $context->builder;
        $entry = $fn->appendBasicBlock('ossl_pbkdf2_entry');
        $b->positionAtEnd($entry);

        $password = $fn->getParam(0);
        $salt = $fn->getParam(1);
        $keyLen = $fn->getParam(2);
        $iterations = $fn->getParam(3);
        $algo = $fn->getParam(4);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $badIter = $b->icmp(LlvmBuilder::INT_SLE, $iterations, $zero);
        $badLen = $b->icmp(LlvmBuilder::INT_SLE, $keyLen, $zero);
        $earlyFail = $fn->appendBasicBlock('ossl_pbkdf2_early_fail');
        $afterGuard = $fn->appendBasicBlock('ossl_pbkdf2_after_guard');
        $b->branchIf($b->bitwiseOr($badIter, $badLen), $earlyFail, $afterGuard);
        $b->positionAtEnd($earlyFail);
        $b->returnValue($nullStr);

        $b->positionAtEnd($afterGuard);
        $hLen = self::emitDigestLen($context, $fn, $algo);
        $unknown = $fn->appendBasicBlock('ossl_pbkdf2_unknown');
        $known = $fn->appendBasicBlock('ossl_pbkdf2_known');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $hLen, $zero), $unknown, $known);
        $b->positionAtEnd($unknown);
        $b->returnValue($nullStr);

        $b->positionAtEnd($known);
        $nblocks = $b->unsignedDiv($b->sub($b->add($keyLen, $hLen), $one), $hLen);
        $outStr = $b->call($context->lookupFunction('__string__alloc'), $keyLen);
        $map = $context->structFieldMap['__string__'];
        $b->store($keyLen, $b->structGep($outStr, $map['length']));
        $outPtr = $b->structGep($outStr, $map['value']);

        $iSlot = $b->alloca($i64);
        $offSlot = $b->alloca($i64);
        $b->store($one, $iSlot);
        $b->store($zero, $offSlot);

        $iHead = $fn->appendBasicBlock('ossl_pbkdf2_i_head');
        $iBody = $fn->appendBasicBlock('ossl_pbkdf2_i_body');
        $iDone = $fn->appendBasicBlock('ossl_pbkdf2_i_done');
        $b->branch($iHead);

        $b->positionAtEnd($iHead);
        $i = $b->load($iSlot);
        $b->branchIf($b->icmp(LlvmBuilder::INT_SLE, $i, $nblocks), $iBody, $iDone);

        $b->positionAtEnd($iBody);
        $blockSalt = self::concatStr($context, $salt, self::uint32be($context, $i));
        $hmacFn = $context->lookupFunction(self::HMAC_ABI);
        $u = $b->call($hmacFn, $algo, $password, $blockSalt);
        $uFail = $fn->appendBasicBlock('ossl_pbkdf2_u_fail');
        $uOk = $fn->appendBasicBlock('ossl_pbkdf2_u_ok');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $u, $nullStr), $uFail, $uOk);
        $b->positionAtEnd($uFail);
        $b->returnValue($nullStr);

        $b->positionAtEnd($uOk);
        $tSlot = $b->alloca($strPtr);
        $uSlot = $b->alloca($strPtr);
        $b->store($u, $tSlot);
        $b->store($u, $uSlot);
        $jSlot = $b->alloca($i64);
        $b->store($i64->constInt(2, false), $jSlot);

        $jHead = $fn->appendBasicBlock('ossl_pbkdf2_j_head');
        $jBody = $fn->appendBasicBlock('ossl_pbkdf2_j_body');
        $jDone = $fn->appendBasicBlock('ossl_pbkdf2_j_done');
        $b->branch($jHead);

        $b->positionAtEnd($jHead);
        $j = $b->load($jSlot);
        $b->branchIf($b->icmp(LlvmBuilder::INT_SLE, $j, $iterations), $jBody, $jDone);

        $b->positionAtEnd($jBody);
        $u2 = $b->call($hmacFn, $algo, $password, $b->load($uSlot));
        $u2Fail = $fn->appendBasicBlock('ossl_pbkdf2_u2_fail');
        $u2Ok = $fn->appendBasicBlock('ossl_pbkdf2_u2_ok');
        $b->branchIf($b->icmp(LlvmBuilder::INT_EQ, $u2, $nullStr), $u2Fail, $u2Ok);
        $b->positionAtEnd($u2Fail);
        $b->returnValue($nullStr);
        $b->positionAtEnd($u2Ok);
        $b->store($u2, $uSlot);
        $b->store(self::xorStr($context, $fn, $b->load($tSlot), $u2), $tSlot);
        $b->store($b->add($j, $one), $jSlot);
        $b->branch($jHead);

        $b->positionAtEnd($jDone);
        $t = $b->load($tSlot);
        $off = $b->load($offSlot);
        $remain = $b->sub($keyLen, $off);
        $ncopy = $b->select($b->icmp(LlvmBuilder::INT_ULT, $remain, $hLen), $remain, $hLen);
        $dest = $b->gep($outPtr, $off);
        $context->intrinsic->builder = $b;
        $context->intrinsic->memcpy(
            $dest,
            self::stringData($context, $t),
            $b->truncOrBitCast($ncopy, $sizeT),
            false
        );
        $b->store($b->add($off, $ncopy), $offSlot);
        $b->store($b->add($i, $one), $iSlot);
        $b->branch($iHead);

        $b->positionAtEnd($iDone);
        $b->returnValue($outStr);
    }

    /** @return Value i64 digest size or 0 */
    private static function emitDigestLen(Context $context, LlvmFunction $fn, Value $algo): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $isSha256 = $context->builder->bitwiseOr(
            self::emitStrEqLit($context, $algo, 'sha256'),
            self::emitStrEqLit($context, $algo, 'SHA256')
        );
        $isSha1 = $context->builder->bitwiseOr(
            self::emitStrEqLit($context, $algo, 'sha1'),
            self::emitStrEqLit($context, $algo, 'SHA1')
        );
        $isMd5 = $context->builder->bitwiseOr(
            self::emitStrEqLit($context, $algo, 'md5'),
            self::emitStrEqLit($context, $algo, 'MD5')
        );
        $lenSha1OrMd5 = $context->builder->select(
            $isSha1,
            $i64->constInt(20, false),
            $context->builder->select($isMd5, $i64->constInt(16, false), $i64->constInt(0, false))
        );

        return $context->builder->select($isSha256, $i64->constInt(32, false), $lenSha1OrMd5);
    }

    private static function emitStrEqLit(Context $context, Value $strPtr, string $lit): Value
    {
        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $len = self::stringLenI64($context, $strPtr);
        $acc = $b->icmp(LlvmBuilder::INT_EQ, $len, $i64->constInt(\strlen($lit), false));
        $data = self::stringData($context, $strPtr);
        $n = \strlen($lit);
        for ($i = 0; $i < $n; ++$i) {
            $byte = $b->load($b->gep($data, $i64->constInt($i, false)));
            $eq = $b->icmp(LlvmBuilder::INT_EQ, $byte, $i8->constInt(\ord($lit[$i]), false));
            $acc = $b->bitwiseAnd($acc, $eq);
        }

        return $acc;
    }

    private static function xorStr(Context $context, LlvmFunction $fn, Value $a, Value $b): Value
    {
        $builder = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $len = self::stringLenI64($context, $a);
        $out = $builder->call($context->lookupFunction('__string__alloc'), $len);
        $map = $context->structFieldMap['__string__'];
        $builder->store($len, $builder->structGep($out, $map['length']));
        $dst = $builder->structGep($out, $map['value']);
        $ap = self::stringData($context, $a);
        $bp = self::stringData($context, $b);
        $iSlot = $builder->alloca($i64);
        $builder->store($i64->constInt(0, false), $iSlot);
        $tag = 'x'.(string) $fn->countBasicBlocks();
        $head = $fn->appendBasicBlock('ossl_xor_h_'.$tag);
        $body = $fn->appendBasicBlock('ossl_xor_b_'.$tag);
        $done = $fn->appendBasicBlock('ossl_xor_d_'.$tag);
        $builder->branch($head);
        $builder->positionAtEnd($head);
        $i = $builder->load($iSlot);
        $builder->branchIf($builder->icmp(LlvmBuilder::INT_ULT, $i, $len), $body, $done);
        $builder->positionAtEnd($body);
        $av = $builder->load($builder->gep($ap, $i));
        $bv = $builder->load($builder->gep($bp, $i));
        $builder->store($builder->bitwiseXor($av, $bv), $builder->gep($dst, $i));
        $builder->store($builder->add($i, $i64->constInt(1, false)), $iSlot);
        $builder->branch($head);
        $builder->positionAtEnd($done);

        return $out;
    }

    private static function uint32be(Context $context, Value $i64v): Value
    {
        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $four = $i64->constInt(4, false);
        $out = $b->call($context->lookupFunction('__string__alloc'), $four);
        $map = $context->structFieldMap['__string__'];
        $b->store($four, $b->structGep($out, $map['length']));
        $p = $b->structGep($out, $map['value']);
        $mask = $i64->constInt(0xff, false);
        $b0 = $b->trunc($b->bitwiseAnd($b->lShr($i64v, $i64->constInt(24, false)), $mask), $i8);
        $b1 = $b->trunc($b->bitwiseAnd($b->lShr($i64v, $i64->constInt(16, false)), $mask), $i8);
        $b2 = $b->trunc($b->bitwiseAnd($b->lShr($i64v, $i64->constInt(8, false)), $mask), $i8);
        $b3 = $b->trunc($b->bitwiseAnd($i64v, $mask), $i8);
        $b->store($b0, $b->gep($p, $i64->constInt(0, false)));
        $b->store($b1, $b->gep($p, $i64->constInt(1, false)));
        $b->store($b2, $b->gep($p, $i64->constInt(2, false)));
        $b->store($b3, $b->gep($p, $i64->constInt(3, false)));

        return $out;
    }

    private static function xorPad64(
        Context $context,
        LlvmFunction $fn,
        Value $src,
        Value $dst,
        int $pad,
        string $tag
    ): void {
        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $iSlot = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $iSlot);
        $head = $fn->appendBasicBlock($tag.'_h');
        $body = $fn->appendBasicBlock($tag.'_b');
        $done = $fn->appendBasicBlock($tag.'_d');
        $b->branch($head);
        $b->positionAtEnd($head);
        $i = $b->load($iSlot);
        $b->branchIf($b->icmp(LlvmBuilder::INT_ULT, $i, $i64->constInt(64, false)), $body, $done);
        $b->positionAtEnd($body);
        $v = $b->load($b->gep($src, $i));
        $b->store($b->bitwiseXor($v, $i8->constInt($pad, false)), $b->gep($dst, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $iSlot);
        $b->branch($head);
        $b->positionAtEnd($done);
    }

    private static function zeroBytes(
        Context $context,
        LlvmFunction $fn,
        Value $dst,
        Value $n,
        string $tag
    ): void {
        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $iSlot = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $iSlot);
        $head = $fn->appendBasicBlock($tag.'_h');
        $body = $fn->appendBasicBlock($tag.'_b');
        $done = $fn->appendBasicBlock($tag.'_d');
        $b->branch($head);
        $b->positionAtEnd($head);
        $i = $b->load($iSlot);
        $b->branchIf($b->icmp(LlvmBuilder::INT_ULT, $i, $n), $body, $done);
        $b->positionAtEnd($body);
        $b->store($i8->constInt(0, false), $b->gep($dst, $i));
        $b->store($b->add($i, $i64->constInt(1, false)), $iSlot);
        $b->branch($head);
        $b->positionAtEnd($done);
    }

    private static function concatStr(Context $context, Value $left, Value $right): Value
    {
        $b = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $leftSize = $b->load($b->structGep($left, $map['length']));
        $rightSize = $b->load($b->structGep($right, $map['length']));
        $size = $b->add($leftSize, $rightSize);
        $result = $b->call($context->lookupFunction('__string__alloc'), $size);
        $b->store($size, $b->structGep($result, $map['length']));
        $dest = $b->structGep($result, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $context->intrinsic->builder = $b;
        $context->intrinsic->memcpy(
            $dest,
            $b->structGep($left, $map['value']),
            $b->truncOrBitCast($leftSize, $sizeT),
            false
        );
        $dest2 = $b->gep($dest, $leftSize);
        $context->intrinsic->memcpy(
            $dest2,
            $b->structGep($right, $map['value']),
            $b->truncOrBitCast($rightSize, $sizeT),
            false
        );

        return $result;
    }

    private static function bytesToString(Context $context, Value $buf, Value $lenI64): Value
    {
        $b = $context->builder;
        $str = $b->call($context->lookupFunction('__string__alloc'), $lenI64);
        $map = $context->structFieldMap['__string__'];
        $b->store($lenI64, $b->structGep($str, $map['length']));
        $sizeT = $context->getTypeFromString('size_t');
        $context->intrinsic->builder = $b;
        $context->intrinsic->memcpy(
            $b->structGep($str, $map['value']),
            $buf,
            $b->truncOrBitCast($lenI64, $sizeT),
            false
        );

        return $str;
    }

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

    private static function stringLenI64(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->load($context->builder->structGep($strPtr, $map['length']));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after OpensslPbkdf2Runtime (#32410)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
