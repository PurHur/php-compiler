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
 * libcrypto EVP RSA keygen leaf for openssl_pkey_new() thin AOT (#34015).
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslPkeyNative::generateRsa}
 * cannot run. Same shape as {@see JitOpensslSignKernel} (#3324).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new) / EVP_PKEY_keygen
 */
final class JitOpensslPkeyKernel
{
    public const EVP_RSA_KEYGEN = '__phpc_ossl_pkey_generate_rsa';

    private const EVP_PKEY_RSA = 6;

    public static function available(): bool
    {
        return VmOpensslPkeyNative::available();
    }

    public static function ensureKeygenLeaf(Context $context): void
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

        $fn = null !== $probe ? $probe : self::declareLeaf($context);
        self::emitGenerateRsa($context, $fn);
        $context->registerFunction(self::EVP_RSA_KEYGEN, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareLeaf(Context $context): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $i64);

        return $context->module->addFunction(self::EVP_RSA_KEYGEN, $ft);
    }

    private static function implementNullStub(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::EVP_RSA_KEYGEN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_rsa_stub')) {
            $context->registerFunction(self::EVP_RSA_KEYGEN, $probe);

            return;
        }
        $fn = null !== $probe ? $probe : self::declareLeaf($context);
        $block = $fn->appendBasicBlock('ossl_llvm_pkey_rsa_stub');
        $context->builder->positionAtEnd($block);
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
        $context->registerFunction(self::EVP_RSA_KEYGEN, $fn);
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
            'BIO_s_mem' => [$i8p, false, []],
            'BIO_new' => [$i8p, false, [$i8p]],
            'BIO_free' => [$ctx->voidType(), false, [$i8p]],
            'BIO_ctrl_pending' => [$sizeT, false, [$i8p]],
            'BIO_read' => [$i32, false, [$i8p, $voidp, $i32]],
            'PEM_write_bio_PrivateKey' => [$i32, false, [$i8p, $i8p, $voidp, $voidp, $i32, $voidp, $voidp]],
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
