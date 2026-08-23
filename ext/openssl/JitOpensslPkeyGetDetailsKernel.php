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
 * libcrypto leaves for openssl_pkey_get_details() thin AOT (#34030).
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslPkeyNative::getDetails}
 * cannot run (peer {@see JitOpensslPkeyKernel} / #34015).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_get_details)
 */
final class JitOpensslPkeyGetDetailsKernel
{
    public const DETAILS_PUB = '__phpc_ossl_pkey_details_pub';

    public const DETAILS_BITS = '__phpc_ossl_pkey_details_bits';

    public const DETAILS_TYPE = '__phpc_ossl_pkey_details_type';

    private const EVP_PKEY_RSA = 6;
    private const EVP_PKEY_RSA2 = 19;
    private const EVP_PKEY_DSA = 116;
    private const EVP_PKEY_DSA1 = 67;
    private const EVP_PKEY_DSA2 = 66;
    private const EVP_PKEY_DSA3 = 113;
    private const EVP_PKEY_DSA4 = 70;
    private const EVP_PKEY_DH = 28;
    private const EVP_PKEY_EC = 408;

    private static int $serial = 0;

    public static function available(): bool
    {
        return VmOpensslPkeyNative::available();
    }

    public static function ensureLeaves(Context $context): void
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

        self::implementIfMissing($context, self::DETAILS_PUB, 'ossl_llvm_pkey_gd_pub', self::emitPub(...));
        self::implementIfMissing($context, self::DETAILS_BITS, 'ossl_llvm_pkey_gd_bits', self::emitBits(...));
        self::implementIfMissing($context, self::DETAILS_TYPE, 'ossl_llvm_pkey_gd_type', self::emitType(...));
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
        if (self::DETAILS_PUB === $abiName) {
            $ft = $context->context->functionType($strPtr, false, $strPtr);
        } else {
            $ft = $context->context->functionType($i64, false, $strPtr);
        }

        return $context->module->addFunction($abiName, $ft);
    }

    private static function implementNullStubs(Context $context): void
    {
        foreach (
            [
                [self::DETAILS_PUB, 'ossl_llvm_pkey_gd_pub_stub', true],
                [self::DETAILS_BITS, 'ossl_llvm_pkey_gd_bits_stub', false],
                [self::DETAILS_TYPE, 'ossl_llvm_pkey_gd_type_stub', false],
            ] as [$abi, $entry, $isStr]
        ) {
            $probe = $context->module->getNamedFunction($abi);
            if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
                $context->registerFunction($abi, $probe);
                continue;
            }
            $fn = null !== $probe ? $probe : self::declareLeaf($context, $abi);
            $block = $fn->appendBasicBlock($entry);
            $context->builder->positionAtEnd($block);
            if ($isStr) {
                $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
            } else {
                $context->builder->returnValue($context->getTypeFromString('int64')->constInt(-1, true));
            }
            $context->registerFunction($abi, $fn);
        }
        $context->builder->clearInsertionPosition();
    }

    private static function emitPub(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_gd_pub');
        $context->builder->positionAtEnd($entry);
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $pkey = self::loadAnyKeyOrReturn($context, $fn, $fn->getParam(0), $nullStr);
        self::emitWritePublicPem($context, $fn, $pkey, $nullStr);
    }

    private static function emitBits(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_gd_bits');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $fail = $i64->constInt(-1, true);
        $pkey = self::loadAnyKeyOrReturn($context, $fn, $fn->getParam(0), $fail);
        $bits = $context->builder->call($context->lookupFunction('EVP_PKEY_get_bits'), $pkey);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $bitsI64 = $context->builder->sext($bits, $i64);
        $ok = $context->builder->icmp(Builder::INT_SGT, $bitsI64, $i64->constInt(0, false));
        $failBb = $fn->appendBasicBlock('ossl_gd_bits_fail');
        $okBb = $fn->appendBasicBlock('ossl_gd_bits_ok');
        $context->builder->branchIf($ok, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($fail);
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($bitsI64);
    }

    private static function emitType(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_gd_type');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $fail = $i64->constInt(-1, true);
        $pkey = self::loadAnyKeyOrReturn($context, $fn, $fn->getParam(0), $fail);
        $baseId = $context->builder->call($context->lookupFunction('EVP_PKEY_get_base_id'), $pkey);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);

        $result = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(-1, true), $result);
        $done = $fn->appendBasicBlock('ossl_gd_type_done');
        $cases = [
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
        $check = $context->builder->getInsertBlock();
        foreach ($cases as $i => [$evpId, $phpType]) {
            $context->builder->positionAtEnd($check);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $baseId,
                $i32->constInt($evpId, false)
            );
            $hit = $fn->appendBasicBlock('ossl_gd_type_hit_'.$i);
            $next = $fn->appendBasicBlock('ossl_gd_type_next_'.$i);
            $context->builder->branchIf($match, $hit, $next);
            $context->builder->positionAtEnd($hit);
            $context->builder->store($i64->constInt($phpType, false), $result);
            $context->builder->branch($done);
            $check = $next;
        }
        $context->builder->positionAtEnd($check);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($result));
    }

    /**
     * Load private or public PEM into EVP_PKEY*; on failure returns $failSentinel from $fn.
     * On success, builder is positioned at a block where the returned Value is the live pkey.
     */
    private static function loadAnyKeyOrReturn(
        Context $context,
        LlvmFunction $fn,
        Value $pemStr,
        Value $failSentinel
    ): Value {
        $id = (string) (++self::$serial);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $voidp = $i8p;
        $strPtr = $context->getTypeFromString('__string__*');

        $pkeySlot = $context->builder->alloca($i8p);
        $context->builder->store($i8p->constNull(), $pkeySlot);

        $failNullPem = $fn->appendBasicBlock('ossl_gd_null_pem_'.$id);
        $havePem = $fn->appendBasicBlock('ossl_gd_have_pem_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pemStr, $strPtr->constNull()),
            $failNullPem,
            $havePem
        );
        $context->builder->positionAtEnd($failNullPem);
        $context->builder->returnValue($failSentinel);

        $context->builder->positionAtEnd($havePem);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($pemStr, $map['length']));
        $empty = $context->builder->icmp(
            Builder::INT_SLE,
            $len,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $failEmpty = $fn->appendBasicBlock('ossl_gd_empty_pem_'.$id);
        $nonEmpty = $fn->appendBasicBlock('ossl_gd_nonempty_pem_'.$id);
        $context->builder->branchIf($empty, $failEmpty, $nonEmpty);
        $context->builder->positionAtEnd($failEmpty);
        $context->builder->returnValue($failSentinel);

        $context->builder->positionAtEnd($nonEmpty);
        $cstr = self::stringToCstr($context, $pemStr);
        $lenI32 = $context->builder->truncOrBitCast($len, $i32);
        $bio = $context->builder->call(
            $context->lookupFunction('BIO_new_mem_buf'),
            $cstr,
            $lenI32
        );
        $failBio = $fn->appendBasicBlock('ossl_gd_fail_bio_'.$id);
        $haveBio = $fn->appendBasicBlock('ossl_gd_have_bio_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );
        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('free'), $cstr);
        $context->builder->returnValue($failSentinel);

        $context->builder->positionAtEnd($haveBio);
        $priv = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio,
            $voidp->constNull(),
            $voidp->constNull(),
            $voidp->constNull()
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $gotPriv = $fn->appendBasicBlock('ossl_gd_got_priv_'.$id);
        $tryPub = $fn->appendBasicBlock('ossl_gd_try_pub_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $priv, $i8p->constNull()),
            $tryPub,
            $gotPriv
        );

        $context->builder->positionAtEnd($gotPriv);
        $context->builder->store($priv, $pkeySlot);
        $context->builder->call($context->lookupFunction('free'), $cstr);
        $doneLoad = $fn->appendBasicBlock('ossl_gd_loaded_'.$id);
        $context->builder->branch($doneLoad);

        $context->builder->positionAtEnd($tryPub);
        $bio2 = $context->builder->call(
            $context->lookupFunction('BIO_new_mem_buf'),
            $cstr,
            $lenI32
        );
        $failBio2 = $fn->appendBasicBlock('ossl_gd_fail_bio2_'.$id);
        $haveBio2 = $fn->appendBasicBlock('ossl_gd_have_bio2_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio2, $i8p->constNull()),
            $failBio2,
            $haveBio2
        );
        $context->builder->positionAtEnd($failBio2);
        $context->builder->call($context->lookupFunction('free'), $cstr);
        $context->builder->returnValue($failSentinel);

        $context->builder->positionAtEnd($haveBio2);
        $pub = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PUBKEY'),
            $bio2,
            $voidp->constNull(),
            $voidp->constNull(),
            $voidp->constNull()
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio2);
        $context->builder->call($context->lookupFunction('free'), $cstr);
        $failPub = $fn->appendBasicBlock('ossl_gd_fail_pub_'.$id);
        $gotPub = $fn->appendBasicBlock('ossl_gd_got_pub_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pub, $i8p->constNull()),
            $failPub,
            $gotPub
        );
        $context->builder->positionAtEnd($failPub);
        $context->builder->returnValue($failSentinel);

        $context->builder->positionAtEnd($gotPub);
        $context->builder->store($pub, $pkeySlot);
        $context->builder->branch($doneLoad);

        $context->builder->positionAtEnd($doneLoad);

        return $context->builder->load($pkeySlot);
    }

    private static function emitWritePublicPem(
        Context $context,
        LlvmFunction $fn,
        Value $pkey,
        Value $nullStr
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $bioMethod = $context->builder->call($context->lookupFunction('BIO_s_mem'));
        $bio = $context->builder->call($context->lookupFunction('BIO_new'), $bioMethod);
        $failBio = $fn->appendBasicBlock('ossl_gd_pub_fail_bio');
        $haveBio = $fn->appendBasicBlock('ossl_gd_pub_have_bio');
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
            $context->lookupFunction('PEM_write_bio_PUBKEY'),
            $bio,
            $pkey
        );
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $failPem = $fn->appendBasicBlock('ossl_gd_pub_fail_pem');
        $havePem = $fn->appendBasicBlock('ossl_gd_pub_have_pem');
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
        $failPending = $fn->appendBasicBlock('ossl_gd_pub_fail_pending');
        $havePending = $fn->appendBasicBlock('ossl_gd_pub_have_pending');
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
        $failMalloc = $fn->appendBasicBlock('ossl_gd_pub_fail_malloc');
        $haveBuf = $fn->appendBasicBlock('ossl_gd_pub_have_buf');
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
        $failRead = $fn->appendBasicBlock('ossl_gd_pub_fail_read');
        $okRead = $fn->appendBasicBlock('ossl_gd_pub_ok_read');
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

    private static function ensureLibcrypto(Context $context): void
    {
        $ctx = $context->context;
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidp = $i8p;
        $sizeT = $context->getTypeFromString('size_t');

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'BIO_new_mem_buf' => [$i8p, false, [$voidp, $i32]],
            'BIO_s_mem' => [$i8p, false, []],
            'BIO_new' => [$i8p, false, [$i8p]],
            'BIO_free' => [$ctx->voidType(), false, [$i8p]],
            'BIO_ctrl_pending' => [$sizeT, false, [$i8p]],
            'BIO_read' => [$i32, false, [$i8p, $voidp, $i32]],
            'PEM_read_bio_PrivateKey' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
            'PEM_read_bio_PUBKEY' => [$i8p, false, [$i8p, $i8p, $voidp, $voidp]],
            'PEM_write_bio_PUBKEY' => [$i32, false, [$i8p, $i8p]],
            'EVP_PKEY_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_PKEY_get_bits' => [$i32, false, [$i8p]],
            'EVP_PKEY_get_base_id' => [$i32, false, [$i8p]],
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
