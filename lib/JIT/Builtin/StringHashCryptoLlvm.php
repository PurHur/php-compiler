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
        self::implementNullAbiIfMissing($context, '__compiler_hash_pbkdf2', 'hc_llvm_pbkdf2_stub');
        self::implementNullAbiIfMissing($context, '__compiler_hash_hkdf', 'hc_llvm_hkdf_stub');
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
            'EVP_Digest' => [$i32, false, [$i8p, $sizeT, $i8p, $i32p, $i8p, $i8p]],
            'HMAC' => [$i8p, false, [$i8p, $i8p, $i32, $i8p, $sizeT, $i8p, $i32p]],
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
