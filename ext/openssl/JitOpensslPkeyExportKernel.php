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
 * libcrypto PEM export leaf for openssl_pkey_export() thin AOT (#34755).
 *
 * Bake-only {@see JitOpensslX509::pkeyExport} requires compile-time PEM literals;
 * runtime OpenSSLAsymmetricKey / strings need this leaf (peer {@see JitOpensslPkeyCryptKernel}).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_export) / PEM_write_bio_PrivateKey
 */
final class JitOpensslPkeyExportKernel
{
    public const EVP_PKEY_EXPORT = '__phpc_ossl_pkey_export';

    public static function available(): bool
    {
        return VmOpensslPkeyNative::available();
    }

    public static function ensureExportLeaf(Context $context): void
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

        $probe = $context->module->getNamedFunction(self::EVP_PKEY_EXPORT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_export_entry')) {
            $context->registerFunction(self::EVP_PKEY_EXPORT, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $fn = null !== $probe ? $probe : self::declareLeaf($context);
        self::emitExport($context, $fn);
        $context->registerFunction(self::EVP_PKEY_EXPORT, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareLeaf(Context $context): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);

        return $context->module->addFunction(self::EVP_PKEY_EXPORT, $ft);
    }

    private static function implementNullStub(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::EVP_PKEY_EXPORT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'ossl_llvm_pkey_export_stub')) {
            $context->registerFunction(self::EVP_PKEY_EXPORT, $probe);

            return;
        }
        $fn = null !== $probe ? $probe : self::declareLeaf($context);
        $block = $fn->appendBasicBlock('ossl_llvm_pkey_export_stub');
        $context->builder->positionAtEnd($block);
        $context->builder->returnValue($context->getTypeFromString('__string__*')->constNull());
        $context->registerFunction(self::EVP_PKEY_EXPORT, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * (__string__* pem, __string__* passphrase_or_null) → __string__* exported PEM or null.
     *
     * Matches {@see VmOpensslPkeyNative::normalizePrivateKeyPem}: read with optional passphrase,
     * rewrite via PEM_write_bio_PrivateKey (3DES when passphrase non-empty).
     */
    private static function emitExport(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_llvm_pkey_export_entry');
        $context->builder->positionAtEnd($entry);

        $pemStr = $fn->getParam(0);
        $passStr = $fn->getParam(1);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullImpl = $i8p->constNull();

        $pkey = self::readPrivateKey($context, $fn, $pemStr, $passStr, 'ossl_pex');
        $failKey = $fn->appendBasicBlock('ossl_pex_fail_key');
        $haveKey = $fn->appendBasicBlock('ossl_pex_have_key');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $pkey, $i8p->constNull()),
            $failKey,
            $haveKey
        );
        $context->builder->positionAtEnd($failKey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveKey);
        $bioMethod = $context->builder->call($context->lookupFunction('BIO_s_mem'));
        $bio = $context->builder->call($context->lookupFunction('BIO_new'), $bioMethod);
        $failBio = $fn->appendBasicBlock('ossl_pex_fail_bio');
        $haveBio = $fn->appendBasicBlock('ossl_pex_have_bio');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $bio, $i8p->constNull()),
            $failBio,
            $haveBio
        );
        $context->builder->positionAtEnd($failBio);
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($haveBio);
        [$cipher, $kstr, $klen] = self::passphraseCipherArgs($context, $fn, $passStr, 'ossl_pex');
        $okPem = $context->builder->call(
            $context->lookupFunction('PEM_write_bio_PrivateKey'),
            $bio,
            $pkey,
            $cipher,
            $kstr,
            $klen,
            $nullImpl,
            $nullImpl
        );
        $context->builder->call($context->lookupFunction('EVP_PKEY_free'), $pkey);
        $failPem = $fn->appendBasicBlock('ossl_pex_fail_pem');
        $havePem = $fn->appendBasicBlock('ossl_pex_have_pem');
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
        $failPending = $fn->appendBasicBlock('ossl_pex_fail_pending');
        $havePending = $fn->appendBasicBlock('ossl_pex_have_pending');
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
        $failMalloc = $fn->appendBasicBlock('ossl_pex_fail_malloc');
        $haveBuf = $fn->appendBasicBlock('ossl_pex_have_buf');
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
        $failRead = $fn->appendBasicBlock('ossl_pex_fail_read');
        $okRead = $fn->appendBasicBlock('ossl_pex_ok_read');
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
     * @return array{0: Value, 1: Value, 2: Value} cipher, kstr, klen
     */
    private static function passphraseCipherArgs(
        Context $context,
        LlvmFunction $fn,
        Value $passStr,
        string $prefix
    ): array {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullImpl = $i8p->constNull();

        $cipherSlot = $context->builder->alloca($i8p);
        $kstrSlot = $context->builder->alloca($i8p);
        $klenSlot = $context->builder->alloca($i32);
        $context->builder->store($nullImpl, $cipherSlot);
        $context->builder->store($nullImpl, $kstrSlot);
        $context->builder->store($i32->constInt(0, false), $klenSlot);

        $noPass = $fn->appendBasicBlock($prefix.'_no_pass');
        $havePassPtr = $fn->appendBasicBlock($prefix.'_have_pass_ptr');
        $donePass = $fn->appendBasicBlock($prefix.'_done_pass');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $passStr, $strPtr->constNull()),
            $noPass,
            $havePassPtr
        );

        $context->builder->positionAtEnd($noPass);
        $context->builder->branch($donePass);

        $context->builder->positionAtEnd($havePassPtr);
        $passLen = self::stringLenI64($context, $passStr);
        $emptyPass = $fn->appendBasicBlock($prefix.'_empty_pass');
        $nonEmptyPass = $fn->appendBasicBlock($prefix.'_nonempty_pass');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $passLen, $i64->constInt(0, false)),
            $emptyPass,
            $nonEmptyPass
        );

        $context->builder->positionAtEnd($emptyPass);
        $context->builder->branch($donePass);

        $context->builder->positionAtEnd($nonEmptyPass);
        $cipher = $context->builder->call($context->lookupFunction('EVP_des_ede3_cbc'));
        $context->builder->store($cipher, $cipherSlot);
        $context->builder->store(self::stringData($context, $passStr), $kstrSlot);
        $context->builder->store(
            $context->builder->truncOrBitCast($passLen, $i32),
            $klenSlot
        );
        $context->builder->branch($donePass);

        $context->builder->positionAtEnd($donePass);

        return [
            $context->builder->load($cipherSlot),
            $context->builder->load($kstrSlot),
            $context->builder->load($klenSlot),
        ];
    }

    private static function readPrivateKey(
        Context $context,
        LlvmFunction $fn,
        Value $pemStr,
        Value $passStr,
        string $prefix
    ): Value {
        $strPtr = $context->getTypeFromString('__string__*');
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
        // PEM_read_bio_PrivateKey: when cb is null, u is the password C string (or null).
        $passArgSlot = $context->builder->alloca($i8p);
        $context->builder->store($nullImpl, $passArgSlot);
        $noPass = $fn->appendBasicBlock($prefix.'_read_no_pass');
        $havePass = $fn->appendBasicBlock($prefix.'_read_have_pass');
        $afterPass = $fn->appendBasicBlock($prefix.'_read_after_pass');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $passStr, $strPtr->constNull()),
            $noPass,
            $havePass
        );
        $context->builder->positionAtEnd($noPass);
        $context->builder->branch($afterPass);
        $context->builder->positionAtEnd($havePass);
        $context->builder->store(self::stringData($context, $passStr), $passArgSlot);
        $context->builder->branch($afterPass);
        $context->builder->positionAtEnd($afterPass);
        $passArg = $context->builder->load($passArgSlot);

        $pkey = $context->builder->call(
            $context->lookupFunction('PEM_read_bio_PrivateKey'),
            $bio,
            $nullImpl,
            $nullImpl,
            $passArg
        );
        $context->builder->call($context->lookupFunction('BIO_free'), $bio);
        $context->builder->call($context->lookupFunction('free'), $pemCstr);
        $context->builder->store($pkey, $slot);
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
            'PEM_write_bio_PrivateKey' => [$i32, false, [$i8p, $i8p, $voidp, $voidp, $i32, $voidp, $voidp]],
            'EVP_PKEY_free' => [$ctx->voidType(), false, [$i8p]],
            'EVP_des_ede3_cbc' => [$i8p, false, []],
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
