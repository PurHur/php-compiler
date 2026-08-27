<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_x509_parse() (#32496 leftover of #6274),
 * openssl_x509_fingerprint() (#32512 leftover of #6524),
 * openssl_x509_checkpurpose() (#32522 leftover of #20286),
 * openssl_x509_check_private_key() (#32527 leftover of #20285),
 * openssl_x509_verify() (#32535 leftover of #6595), and
 * openssl_x509_export() (#32557 leftover of #20273),
 * openssl_x509_export_to_file() (#32557 leftover of #20273),
 * openssl_csr_get_subject() (#32692 leftover of #6421),
 * openssl_csr_export() (#32697 leftover of #6421),
 * openssl_csr_export_to_file() (#32697 leftover of #6421),
 * openssl_pkey_export() (#32705 leftover of #6295; runtime key #34755),
 * openssl_pkey_export_to_file() (#32705 leftover of #20287; runtime key #34755),
 * openssl_public_encrypt() (#32713 leftover of #6666; runtime key #34722; softfail warn #35382),
 * openssl_private_encrypt() (#32757 leftover of #6666; runtime key #34722; softfail warn #35382),
 * openssl_private_decrypt() (#32759 leftover of #6666; runtime key #34722; softfail warn #35382),
 * openssl_public_decrypt() (#32761 leftover of #6666; runtime key #34722; softfail warn #35382),
 * openssl_dh_compute_key() (#32771 leftover of #6596),
 * openssl_pkey_derive() (#32852 leftover of #15428), and
 * openssl_spki_verify() (#32776 leftover of #8690);
 * openssl_spki_export() (#32787 leftover of #6423);
 * openssl_spki_export_challenge() (#32792 leftover of #6423);
 * openssl_spki_new() (#32892 leftover of #8690);
 * openssl_pkcs12_export() (#32948 leftover of #6420);
 * openssl_pkcs12_export_to_file() (#32948 leftover of #6420);
 * openssl_pkcs12_read() (#33444 leftover of #6420);
 * openssl_pkcs7_read() (#33458 leftover of #20305);
 * openssl_cms_read() (#33460 leftover of #6592);
 * openssl_cms_verify() (#33464 leftover of #6592);
 * openssl_cms_sign() (#33467 leftover of #6592);
 * openssl_cms_encrypt() (#33473 leftover of #6592);
 * openssl_cms_decrypt() (#33479 leftover of #6592);
 * openssl_pkcs7_verify() (#33466 leftover of #6804);
 * openssl_pkcs7_sign() (#33471 leftover of #6804);
 * openssl_pkcs7_encrypt() (#33474 leftover of #6804);
 * openssl_seal() / openssl_open() (#32979 leftover of #6523).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_parse)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_checkpurpose) / check_cert / X509_verify_cert
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_check_private_key) / X509_check_private_key
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_verify) / X509_verify
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_export) / PEM_write_bio_X509 / X509_print
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_export_to_file)
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_csr_get_subject) / X509_REQ_get_subject_name
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_csr_export) / PEM_write_bio_X509_REQ
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_csr_export_to_file)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_export) / PEM_write_bio_PrivateKey
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_export_to_file)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_public_encrypt) / EVP_PKEY_encrypt
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_private_encrypt) / EVP_PKEY_sign
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_private_decrypt) / EVP_PKEY_decrypt
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_public_decrypt) / EVP_PKEY_verify_recover
 * php-src: ext/openssl/openssl_backend_v3.c — PHP_FUNCTION(openssl_dh_compute_key) / EVP_PKEY_derive
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_derive) / EVP_PKEY_derive
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_spki_verify) / NETSCAPE_SPKI_verify
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_spki_export) / NETSCAPE_SPKI_get_pubkey
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_spki_export_challenge) / NETSCAPE_SPKI_get_challenge
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_spki_new) / NETSCAPE_SPKI_sign
 * php-src: ext/openssl/pkcs12.c — PHP_FUNCTION(openssl_pkcs12_export) / PKCS12_create
 * php-src: ext/openssl/pkcs12.c — PHP_FUNCTION(openssl_pkcs12_export_to_file)
 * php-src: ext/openssl/pkcs12.c — PHP_FUNCTION(openssl_pkcs12_read) / PKCS12_parse
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkcs7_read) / PEM_read_bio_PKCS7
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_cms_read) / PEM_read_bio_CMS / CMS_get1_certs
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_cms_verify) / CMS_verify
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_cms_sign) / CMS_sign
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_cms_encrypt) / CMS_encrypt
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_cms_decrypt) / CMS_decrypt
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslX509Native} cannot
 * call `$ffi->X509_free()` (peer JitOpensslError / #32336). Bake results in the
 * compiler process (which does have libcrypto FFI), like {@see JitOpensslMethods::certLocations()}.
 *
 * PEM and optional args must be compile-time literals. OpenSSLCertificate objects stay VM-only.
 */
final class JitOpensslX509
{
    private static int $blockSerial = 0;

    public static function parse(Context $context, JITVariable $certificate, ?JITVariable $shortNames = null): Value
    {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_parse() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32496)'
            );
        }
        $short = self::compileTimeBool($shortNames, true);
        if (null === $short) {
            throw new \LogicException(
                'openssl_x509_parse() short_names must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32496)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $parsed = VmOpensslX509Native::parseCertificatePem($pem, $short);
        if (false === $parsed) {
            return self::boxedFalse($context);
        }

        $htVar = HashTableHelper::variableFromVmHashTable(
            $context,
            VmOpensslObjects::variableFromPhpValue($parsed)->toArray()
        );

        return $htVar->value;
    }

    /**
     * openssl_csr_get_subject() — bake {@see VmOpensslCsrNative::getSubject}.
     *
     * php-src: ext/openssl/xp.c PHP_FUNCTION(openssl_csr_get_subject) / X509_REQ_get_subject_name
     */
    public static function csrGetSubject(Context $context, JITVariable $csr, ?JITVariable $shortNames = null): Value
    {
        $pem = JitStringArg::compileTimeLiteral($csr);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_csr_get_subject() csr must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32692)'
            );
        }
        $short = self::compileTimeBool($shortNames, true);
        if (null === $short) {
            throw new \LogicException(
                'openssl_csr_get_subject() short_names must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32692)'
            );
        }

        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $subject = VmOpensslCsrNative::getSubject($pem, $short);
        if (false === $subject) {
            return self::boxedFalse($context);
        }

        $htVar = HashTableHelper::variableFromVmHashTable(
            $context,
            VmOpensslObjects::variableFromPhpValue($subject)->toArray()
        );

        return $htVar->value;
    }

    /**
     * openssl_csr_export() — bake {@see VmOpensslCsrNative::normalizeCsrPem} into &$output.
     *
     * php-src: ext/openssl/xp.c PHP_FUNCTION(openssl_csr_export) / PEM_write_bio_X509_REQ
     * VM {@see VmOpenssl::csrExportPem} always writes PEM (no_text text dump is not implemented).
     * By-ref $output is written via __value__writeString (peer {@see self::export}).
     */
    public static function csrExport(
        Context $context,
        JITVariable $csr,
        JITVariable $output,
        ?JITVariable $noText = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($csr);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_csr_export() csr must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32697)'
            );
        }
        $noTextBool = self::compileTimeBool($noText, true);
        if (null === $noTextBool) {
            throw new \LogicException(
                'openssl_csr_export() no_text must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32697)'
            );
        }
        unset($noTextBool);

        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslCsrNative::normalizeCsrPem($pem);
        if (false === $exported) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
        $str = $context->builder->load($context->constantStringFromString($exported));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_csr_export_to_file() — bake {@see VmOpensslCsrNative::normalizeCsrPem}, write via
     * {@see \PHPCompiler\JIT\Builtin\StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/xp.c PHP_FUNCTION(openssl_csr_export_to_file)
     */
    public static function csrExportToFile(
        Context $context,
        JITVariable $csr,
        JITVariable $outputFilename,
        ?JITVariable $noText = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($csr);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_csr_export_to_file() csr must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32697)'
            );
        }
        $path = JitStringArg::compileTimeLiteral($outputFilename);
        if (null === $path) {
            throw new \LogicException(
                'openssl_csr_export_to_file() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32697)'
            );
        }
        $noTextBool = self::compileTimeBool($noText, true);
        if (null === $noTextBool) {
            throw new \LogicException(
                'openssl_csr_export_to_file() no_text must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32697)'
            );
        }
        unset($noTextBool);

        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslCsrNative::normalizeCsrPem($pem);
        if (false === $exported) {
            return self::boxedFalse($context);
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));
        $dataStr = $context->builder->load($context->constantStringFromString($exported));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_csr_export_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_csr_export_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_csr_export_file_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkey_export() — bake when key+passphrase are literals; else runtime leaf (#34755).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_export) / PEM_write_bio_PrivateKey
     * $options is type-checked only (VM {@see openssl_pkey_export} ignores cipher config).
     */
    public static function pkeyExport(
        Context $context,
        JITVariable $key,
        JITVariable $output,
        ?JITVariable $passphrase = null,
        ?JITVariable $options = null
    ): Value {
        if (!self::compileTimeOptionsOk($options)) {
            throw new \LogicException(
                'openssl_pkey_export() options must be a compile-time ?array '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }

        $baked = self::tryBakePkeyExport($context, $key, $passphrase);
        if (null !== $baked) {
            if (false === $baked) {
                return self::boxedFalse($context);
            }
            $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
            $str = $context->builder->load($context->constantStringFromString($baked));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $outPtr,
                $str
            );
            JitValueBox::publishAfterWrite($context, $outPtr);

            return self::boxedBool($context, true);
        }

        return self::pkeyExportRuntime($context, $key, $output, $passphrase, 'openssl_pkey_export');
    }

    /**
     * openssl_pkey_export_to_file() — bake when literals; else runtime export + file write (#34755).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_export_to_file)
     */
    public static function pkeyExportToFile(
        Context $context,
        JITVariable $key,
        JITVariable $outputFilename,
        ?JITVariable $passphrase = null,
        ?JITVariable $options = null
    ): Value {
        if (!self::compileTimeOptionsOk($options)) {
            throw new \LogicException(
                'openssl_pkey_export_to_file() options must be a compile-time ?array '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }

        $pathLit = JitStringArg::compileTimeLiteral($outputFilename);
        $baked = null !== $pathLit ? self::tryBakePkeyExport($context, $key, $passphrase) : null;
        if (null !== $baked && null !== $pathLit) {
            if (false === $baked) {
                return self::boxedFalse($context);
            }

            $pathStr = $context->builder->load($context->constantStringFromString($pathLit));
            $dataStr = $context->builder->load($context->constantStringFromString($baked));
            $dataOwned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $dataStr
            );
            $written = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataOwned,
                $context->getTypeFromString('int64')->constInt(0, false)
            );

            return self::boxedBoolFromI64WriteResult($context, $written, 'ossl_pkey_export_file');
        }

        return self::pkeyExportToFileRuntime($context, $key, $outputFilename, $passphrase);
    }

    /**
     * Compile-time bake when key PEM + passphrase are literals; otherwise null (#34755).
     *
     * @return string|false|null exported PEM, false on native failure, null when not bakeable
     */
    private static function tryBakePkeyExport(
        Context $context,
        JITVariable $key,
        ?JITVariable $passphrase
    ): string|false|null {
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            return null;
        }
        $pass = self::compileTimeNullableString($passphrase);
        if (null === $pass) {
            return null;
        }
        if (!VmOpensslPkeyNative::available()) {
            return false;
        }

        return VmOpensslPkeyNative::exportPrivateKeyPem($pem, $pass[0]);
    }

    /**
     * Runtime openssl_pkey_export via {@see JitOpensslPkeyExportKernel} (#34755).
     */
    private static function pkeyExportRuntime(
        Context $context,
        JITVariable $key,
        JITVariable $output,
        ?JITVariable $passphrase,
        string $fnName
    ): Value {
        JitOpensslPkeyExportKernel::ensureExportLeaf($context);

        $pemStr = JitOpensslPkeyGetPublic::resolvePemString($context, $key);
        $passStr = null === $passphrase
            ? $context->getTypeFromString('__string__*')->constNull()
            : JitStringBuiltinArg::lowerNullableString($context, $passphrase, $fnName, 2, 'passphrase');

        $raw = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyExportKernel::EVP_PKEY_EXPORT),
            $pemStr,
            $passStr
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_rt_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_rt_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_rt_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $raw
        );
        JitValueBox::publishAfterWrite($context, $outPtr);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * Runtime openssl_pkey_export_to_file (#34755).
     */
    private static function pkeyExportToFileRuntime(
        Context $context,
        JITVariable $key,
        JITVariable $outputFilename,
        ?JITVariable $passphrase
    ): Value {
        JitOpensslPkeyExportKernel::ensureExportLeaf($context);
        StringFilePutContents::ensureLinked($context);

        $pemStr = JitOpensslPkeyGetPublic::resolvePemString($context, $key);
        $passStr = null === $passphrase
            ? $context->getTypeFromString('__string__*')->constNull()
            : JitStringBuiltinArg::lowerNullableString(
                $context,
                $passphrase,
                'openssl_pkey_export_to_file',
                2,
                'passphrase'
            );
        $pathStr = JitStringBuiltinArg::lowerPath(
            $context,
            $outputFilename,
            'openssl_pkey_export_to_file',
            1,
            'output_filename'
        );

        $raw = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyExportKernel::EVP_PKEY_EXPORT),
            $pemStr,
            $passStr
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_rt_fail_'.$id);
        $writeBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_rt_write_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_rt_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $writeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($writeBlock);
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $raw
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(
            Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $writeFail = BasicBlockHelper::append($context, 'ossl_pkey_export_file_rt_wfail_'.$id);
        $writeOk = BasicBlockHelper::append($context, 'ossl_pkey_export_file_rt_wok_'.$id);
        $context->builder->branchIf($failed, $writeFail, $writeOk);

        $context->builder->positionAtEnd($writeFail);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($writeOk);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function boxedBoolFromI64WriteResult(Context $context, Value $written, string $prefix): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(
            Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, $prefix.'_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, $prefix.'_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, $prefix.'_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_public_encrypt() — bake when data+key+padding are compile-time literals;
     * otherwise runtime EVP leaf (#34722 peer #34715).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_public_encrypt) / EVP_PKEY_encrypt
     * By-ref $encrypted is written via __value__writeString (peer {@see self::pkeyExport}).
     *
     * PKCS#1 padding is non-deterministic; baking a ciphertext is still correct for a
     * compiled binary (fixed bytes every run). Repros should assert bool + length.
     */
    public static function publicEncrypt(
        Context $context,
        JITVariable $data,
        JITVariable $encrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $baked = self::tryBakeAsymmetricCrypt(
            $context,
            $data,
            $encrypted,
            $key,
            $padding,
            static fn (string $plain, string $pem, int $pad): string|false => VmOpensslPkeyNative::encrypt($plain, $pem, $pad),
            'openssl_public_encrypt',
            false
        );
        if (null !== $baked) {
            return $baked;
        }

        return self::asymmetricCryptRuntime(
            $context,
            $data,
            $encrypted,
            $key,
            $padding,
            JitOpensslPkeyCryptKernel::EVP_PUBLIC_ENCRYPT,
            'openssl_public_encrypt',
            false
        );
    }

    /**
     * openssl_private_encrypt() — bake when literals; else runtime leaf (#34722).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_private_encrypt) / EVP_PKEY_sign
     */
    public static function privateEncrypt(
        Context $context,
        JITVariable $data,
        JITVariable $encrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $baked = self::tryBakeAsymmetricCrypt(
            $context,
            $data,
            $encrypted,
            $key,
            $padding,
            static fn (string $plain, string $pem, int $pad): string|false => VmOpensslPkeyNative::privateEncrypt($plain, $pem, $pad),
            'openssl_private_encrypt',
            true
        );
        if (null !== $baked) {
            return $baked;
        }

        return self::asymmetricCryptRuntime(
            $context,
            $data,
            $encrypted,
            $key,
            $padding,
            JitOpensslPkeyCryptKernel::EVP_PRIVATE_ENCRYPT,
            'openssl_private_encrypt',
            true
        );
    }

    /**
     * openssl_private_decrypt() — bake when literals; else runtime leaf (#34722).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_private_decrypt) / EVP_PKEY_decrypt
     */
    public static function privateDecrypt(
        Context $context,
        JITVariable $data,
        JITVariable $decrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $baked = self::tryBakeAsymmetricCrypt(
            $context,
            $data,
            $decrypted,
            $key,
            $padding,
            static fn (string $cipher, string $pem, int $pad): string|false => VmOpensslPkeyNative::decrypt($cipher, $pem, $pad),
            'openssl_private_decrypt',
            true
        );
        if (null !== $baked) {
            return $baked;
        }

        return self::asymmetricCryptRuntime(
            $context,
            $data,
            $decrypted,
            $key,
            $padding,
            JitOpensslPkeyCryptKernel::EVP_PRIVATE_DECRYPT,
            'openssl_private_decrypt',
            true
        );
    }

    /**
     * openssl_public_decrypt() — bake when literals; else runtime leaf (#34722).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_public_decrypt) / EVP_PKEY_verify_recover
     */
    public static function publicDecrypt(
        Context $context,
        JITVariable $data,
        JITVariable $decrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $baked = self::tryBakeAsymmetricCrypt(
            $context,
            $data,
            $decrypted,
            $key,
            $padding,
            static fn (string $cipher, string $pem, int $pad): string|false => VmOpensslPkeyNative::publicDecrypt($cipher, $pem, $pad),
            'openssl_public_decrypt',
            false
        );
        if (null !== $baked) {
            return $baked;
        }

        return self::asymmetricCryptRuntime(
            $context,
            $data,
            $decrypted,
            $key,
            $padding,
            JitOpensslPkeyCryptKernel::EVP_PUBLIC_DECRYPT,
            'openssl_public_decrypt',
            false
        );
    }

    /**
     * Compile-time bake when data, key PEM, and padding are all literals; otherwise null (#34722).
     * Softfail emits Zend-shaped E_WARNING (#35382 leftover of #32713).
     *
     * @param callable(string, string, int): (string|false) $native
     */
    private static function tryBakeAsymmetricCrypt(
        Context $context,
        JITVariable $data,
        JITVariable $out,
        JITVariable $key,
        ?JITVariable $padding,
        callable $native,
        string $fnName,
        bool $privateKey
    ): ?Value {
        $payload = JitStringArg::compileTimeLiteral($data);
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $payload || null === $pem) {
            return null;
        }
        $pad = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (null !== $padding) {
            $padLit = self::compileTimeInt($padding);
            if (null === $padLit) {
                return null;
            }
            $pad = $padLit;
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $result = $native($payload, $pem, $pad);
        if (false === $result) {
            self::asymmetricInvalidKeyWarning($context, $fnName, $privateKey, $pem);

            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $out);
        $str = $context->builder->load($context->constantStringFromString($result));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * Runtime asymmetric crypt via {@see JitOpensslPkeyCryptKernel}; key via resolvePemString (#34722).
     * Fail path emits E_WARNING (#35382).
     */
    private static function asymmetricCryptRuntime(
        Context $context,
        JITVariable $data,
        JITVariable $out,
        JITVariable $key,
        ?JITVariable $padding,
        string $evpLeaf,
        string $fnName,
        bool $privateKey
    ): Value {
        JitOpensslPkeyCryptKernel::ensureEvpLeaves($context);

        $dataStr = JitStringBuiltinArg::lowerStrictOrCoercible($context, $data, $fnName, 0, 'data');
        $pemStr = JitOpensslPkeyGetPublic::resolvePemString($context, $key);
        $padVal = null === $padding
            ? $context->getTypeFromString('int64')->constInt(OpensslConstants::OPENSSL_PKCS1_PADDING, false)
            : JitLongArg::lower($context, $padding, $fnName.'(): Argument #4 ($padding)');

        $raw = $context->builder->call(
            $context->lookupFunction($evpLeaf),
            $dataStr,
            $pemStr,
            $padVal
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_crypt_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_crypt_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_crypt_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        self::asymmetricInvalidKeyWarning($context, $fnName, $privateKey, null);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::valuePtrFromVariable($context, $out),
            $raw
        );
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * Zend-shaped softfail E_WARNING for asymmetric crypt (#35382).
     *
     * php-src: ext/openssl/openssl.c — php_openssl_pkey_from_zval / encrypt|decrypt failure
     */
    private static function asymmetricInvalidKeyWarning(
        Context $context,
        string $fnName,
        bool $privateKey,
        ?string $pemLiteral
    ): void {
        JitBuiltinWarning::emit($context, self::asymmetricCryptSoftfailMessage($fnName, $privateKey, $pemLiteral));
    }

    /**
     * Prefer Zend invalid-key wording when the PEM literal fails to parse; else Encryption/Decryption failed.
     */
    private static function asymmetricCryptSoftfailMessage(
        string $fnName,
        bool $privateKey,
        ?string $pemLiteral
    ): string {
        if (null !== $pemLiteral && VmOpensslPkeyNative::available()) {
            $keyOk = $privateKey
                ? false !== VmOpensslPkeyNative::normalizePrivateKeyPem($pemLiteral)
                : false !== VmOpensslPkeyNative::normalizePublicKeyPem($pemLiteral);
            if (!$keyOk) {
                return match ($fnName) {
                    'openssl_private_encrypt' => 'openssl_private_encrypt(): key param is not a valid private key',
                    'openssl_private_decrypt' => 'openssl_private_decrypt(): key parameter is not a valid private key',
                    'openssl_public_decrypt' => 'openssl_public_decrypt(): key parameter is not a valid public key',
                    default => 'openssl_public_encrypt(): key parameter is not a valid public key',
                };
            }
        }

        $failed = \str_contains($fnName, 'decrypt') ? 'Decryption failed' : 'Encryption failed';

        return $fnName.'(): '.$failed;
    }

    /**
     * openssl_dh_compute_key() — bake {@see VmOpensslPkeyDeriveNative::dhComputeKey}.
     *
     * php-src: ext/openssl/openssl_backend_v3.c PHP_FUNCTION(openssl_dh_compute_key) / EVP_PKEY_derive
     * Peer public key is raw encoded bytes; private key is PEM (same coerce surface as VM).
     */
    public static function dhComputeKey(
        Context $context,
        JITVariable $publicKey,
        JITVariable $privateKey
    ): Value {
        $pub = JitStringArg::compileTimeLiteral($publicKey);
        if (null === $pub) {
            throw new \LogicException(
                'openssl_dh_compute_key() public_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32771)'
            );
        }
        $pem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_dh_compute_key() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32771)'
            );
        }

        if (!VmOpensslPkeyDeriveNative::available()) {
            return self::boxedFalse($context);
        }

        $shared = VmOpensslPkeyDeriveNative::dhComputeKey($pem, $pub);
        if (false === $shared) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $shared);
    }

    /**
     * openssl_pkey_derive() — bake {@see VmOpensslPkeyDeriveNative::derive}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_derive) / EVP_PKEY_derive
     * Public + private keys are PEM string literals (same coerce surface as thin AOT
     * {@see self::dhComputeKey}). Optional $key_length must be a compile-time int.
     *
     * Compile-time null/bool/int/float soft-fail to false (php-src "zz|l" +
     * php_openssl_pkey_from_zval; VM #26689). Private is checked first (php-src load order).
     */
    public static function pkeyDerive(
        Context $context,
        JITVariable $publicKey,
        JITVariable $privateKey,
        ?JITVariable $keyLength = null
    ): Value {
        // php-src loads private_key first — soft-fail scalars never reach public_key (#26689 / #32852).
        if (self::isCompileTimeDeriveScalarSoftFail($privateKey)
            || self::isCompileTimeDeriveScalarSoftFail($publicKey)
        ) {
            return self::boxedFalse($context);
        }

        $pub = JitStringArg::compileTimeLiteral($publicKey);
        if (null === $pub) {
            throw new \LogicException(
                'openssl_pkey_derive() public_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32852)'
            );
        }
        $priv = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $priv) {
            throw new \LogicException(
                'openssl_pkey_derive() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32852)'
            );
        }
        $len = 0;
        if (null !== $keyLength) {
            $lenLit = self::compileTimeInt($keyLength);
            if (null === $lenLit) {
                throw new \LogicException(
                    'openssl_pkey_derive() key_length must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #32852)'
                );
            }
            $len = $lenLit;
        }

        if (!VmOpensslPkeyDeriveNative::available()) {
            return self::boxedFalse($context);
        }

        $shared = VmOpensslPkeyDeriveNative::derive($pub, $priv, $len);
        if (false === $shared) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $shared);
    }

    /**
     * Compile-time non-string/non-object scalars soft-fail for openssl_pkey_derive (#26689).
     * Arrays are not included — incomplete key arrays raise ValueError on VM/Zend.
     */
    private static function isCompileTimeDeriveScalarSoftFail(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return true;
        }

        return match ($arg->type) {
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::TYPE_NATIVE_DOUBLE => true,
            default => false,
        };
    }

    /**
     * openssl_spki_new() — bake {@see VmOpensslSpkiNative::spkiNew}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_spki_new) /
     * NETSCAPE_SPKI_new + NETSCAPE_SPKI_sign + NETSCAPE_SPKI_b64_encode
     * Private key and challenge must be compile-time string literals; optional digest is a
     * compile-time int ({@see OpensslConstants::OPENSSL_ALGO_*}) or string name. Default digest
     * matches VM ({@see OpensslConstants::OPENSSL_ALGO_MD5}).
     */
    public static function spkiNew(
        Context $context,
        JITVariable $privateKey,
        JITVariable $challenge,
        ?JITVariable $digestAlgo = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_spki_new() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32892)'
            );
        }
        $chal = JitStringArg::compileTimeLiteral($challenge);
        if (null === $chal) {
            throw new \LogicException(
                'openssl_spki_new() challenge must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32892)'
            );
        }

        $algorithm = OpensslConstants::OPENSSL_ALGO_MD5;
        if (null !== $digestAlgo) {
            $algoInt = self::compileTimeInt($digestAlgo);
            if (null !== $algoInt) {
                $algorithm = $algoInt;
            } else {
                $algoStr = JitStringArg::compileTimeLiteral($digestAlgo);
                if (null === $algoStr) {
                    throw new \LogicException(
                        'openssl_spki_new() digest_algo must be a compile-time int or string '
                        .'for JIT/AOT in this compiler build (issue #32892)'
                    );
                }
                $algorithm = $algoStr;
            }
        }

        if (!VmOpensslSpkiNative::available()) {
            return self::boxedFalse($context);
        }

        $digestName = VmOpenssl::resolveDigestName($algorithm, 'openssl_spki_new', null);
        if (false === $digestName) {
            return self::boxedFalse($context);
        }

        $spkac = VmOpensslSpkiNative::spkiNew($pem, $chal, $digestName);
        if (false === $spkac) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $spkac);
    }

    /**
     * openssl_pkcs12_read() — bake {@see VmOpensslPkcs12Native::parsePkcs12} into &$certificates.
     *
     * php-src: ext/openssl/pkcs12.c PHP_FUNCTION(openssl_pkcs12_read) / PKCS12_parse
     * By-ref $certificates is written via __value__writeHashtable (peer {@see self::seal}).
     *
     * PKCS#12 blob and passphrase must be compile-time string literals (thin AOT has no PHP FFI).
     */
    public static function pkcs12Read(
        Context $context,
        JITVariable $pkcs12,
        JITVariable $certificates,
        JITVariable $passphrase
    ): Value {
        $blob = JitStringArg::compileTimeLiteral($pkcs12);
        if (null === $blob) {
            throw new \LogicException(
                'openssl_pkcs12_read() pkcs12 must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33444)'
            );
        }
        $pass = JitStringArg::compileTimeLiteral($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkcs12_read() passphrase must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33444)'
            );
        }

        if (!VmOpensslPkcs12Native::available()) {
            return self::boxedFalse($context);
        }

        $parsed = VmOpensslPkcs12Native::parsePkcs12($blob, $pass);
        if (false === $parsed) {
            return self::boxedFalse($context);
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($parsed as $key => $pem) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string($pem);
            $ht->update($key, $var);
        }
        $htJit = HashTableHelper::variableFromVmHashTable($context, $ht);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $certificates);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $outPtr,
            $context->helper->loadValue($htJit)
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_pkcs7_read() — bake {@see VmOpensslPkcs7Native::read} into &$certificates.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_read) / PEM_read_bio_PKCS7
     * By-ref $certificates is written via __value__writeHashtable (peer {@see self::pkcs12Read}).
     *
     * PKCS#7 PEM content must be a compile-time string literal (thin AOT has no PHP FFI).
     */
    public static function pkcs7Read(
        Context $context,
        JITVariable $data,
        JITVariable $certificates
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($data);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_pkcs7_read() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33458)'
            );
        }

        if (!VmOpensslPkcs7Native::available()) {
            return self::boxedFalse($context);
        }

        $certs = VmOpensslPkcs7Native::read($pem);
        if (false === $certs) {
            return self::boxedFalse($context);
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($certs as $certPem) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string($certPem);
            $ht->append($var);
        }
        $htJit = HashTableHelper::variableFromVmHashTable($context, $ht);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $certificates);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $outPtr,
            $context->helper->loadValue($htJit)
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_cms_read() — bake {@see VmOpensslCmsNative::read} into &$certificates.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_read) / PEM_read_bio_CMS / CMS_get1_certs
     * By-ref $certificates is written via __value__writeHashtable (peer {@see self::pkcs7Read}).
     *
     * CMS PEM content must be a compile-time string literal (thin AOT has no PHP FFI).
     * OpenSSL also accepts PKCS#7 PEM envelopes for this path (peer Zend / fixture cert.p7b).
     */
    public static function cmsRead(
        Context $context,
        JITVariable $data,
        JITVariable $certificates
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($data);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_cms_read() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33460)'
            );
        }

        if (!VmOpensslCmsNative::available()) {
            return self::boxedFalse($context);
        }

        $certs = VmOpensslCmsNative::read($pem);
        if (false === $certs) {
            return self::boxedFalse($context);
        }

        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($certs as $certPem) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string($certPem);
            $ht->append($var);
        }
        $htJit = HashTableHelper::variableFromVmHashTable($context, $ht);
        $outPtr = JitValueBox::valuePtrFromVariable($context, $certificates);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $outPtr,
            $context->helper->loadValue($htJit)
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_cms_verify() — bake {@see VmOpensslCmsNative::verify}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_verify) / CMS_verify
     *
     * Input path, flags, encoding, and optional content/signers paths must be compile-time
     * literals (thin AOT has no PHP FFI). Verify runs in the compiler process; content and
     * signers PEMs are emitted via {@see StringFilePutContents} at runtime when those paths
     * are provided (peer {@see self::exportToFile}).
     *
     * $ca_info / $untrusted / unused trailing path args are type-checked only (VM peer ignores
     * them in {@see VmOpenssl::cmsVerify}).
     */
    public static function cmsVerify(
        Context $context,
        JITVariable $input,
        ?JITVariable $flags = null,
        ?JITVariable $certificates = null,
        ?JITVariable $caInfo = null,
        ?JITVariable $untrusted = null,
        ?JITVariable $content = null,
        ?JITVariable $pk7 = null,
        ?JITVariable $sigfile = null,
        ?JITVariable $encoding = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_cms_verify() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33464)'
            );
        }

        $flagsVal = 0;
        if (null !== $flags && !NamedOptionalCallArgs::isOmittedOptional($flags)) {
            $parsed = self::compileTimeInt($flags);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_cms_verify() flags must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33464)'
                );
            }
            $flagsVal = $parsed;
        }

        $signersPath = self::compileTimeNullableString(
            (null !== $certificates && !NamedOptionalCallArgs::isOmittedOptional($certificates))
                ? $certificates
                : null
        );
        if (null === $signersPath) {
            throw new \LogicException(
                'openssl_cms_verify() certificates must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #33464)'
            );
        }

        if (null !== $caInfo && !NamedOptionalCallArgs::isOmittedOptional($caInfo)
            && !self::compileTimeOptionsOk($caInfo)
            && null === self::compileTimeStringList($caInfo)
        ) {
            throw new \LogicException(
                'openssl_cms_verify() ca_info must be a compile-time array '
                .'for JIT/AOT in this compiler build (issue #33464)'
            );
        }

        if (null !== $untrusted && !NamedOptionalCallArgs::isOmittedOptional($untrusted)) {
            $untrustedPath = self::compileTimeNullableString($untrusted);
            if (null === $untrustedPath) {
                throw new \LogicException(
                    'openssl_cms_verify() untrusted_certificates_filename must be a compile-time string or null '
                    .'for JIT/AOT in this compiler build (issue #33464)'
                );
            }
        }

        $contentPath = self::compileTimeNullableString(
            (null !== $content && !NamedOptionalCallArgs::isOmittedOptional($content))
                ? $content
                : null
        );
        if (null === $contentPath) {
            throw new \LogicException(
                'openssl_cms_verify() content must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #33464)'
            );
        }

        foreach ([$pk7, $sigfile] as $extra) {
            if (null === $extra || NamedOptionalCallArgs::isOmittedOptional($extra)) {
                continue;
            }
            if (null === self::compileTimeNullableString($extra)) {
                throw new \LogicException(
                    'openssl_cms_verify() optional path args must be compile-time strings or null '
                    .'for JIT/AOT in this compiler build (issue #33464)'
                );
            }
        }

        $encodingVal = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if (null !== $encoding && !NamedOptionalCallArgs::isOmittedOptional($encoding)) {
            $parsedEnc = self::compileTimeInt($encoding);
            if (null === $parsedEnc) {
                throw new \LogicException(
                    'openssl_cms_verify() encoding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33464)'
                );
            }
            $encodingVal = $parsedEnc;
        }

        if (!VmOpensslCmsNative::available()) {
            return self::boxedFalse($context);
        }

        $bakeContent = null;
        $bakeSigners = null;
        $contentBytes = null;
        $signersBytes = null;
        try {
            if (null !== $contentPath[0]) {
                $bakeContent = tempnam(sys_get_temp_dir(), 'phpc_cms_v_c_');
                if (false === $bakeContent) {
                    return self::boxedFalse($context);
                }
            }
            if (null !== $signersPath[0]) {
                $bakeSigners = tempnam(sys_get_temp_dir(), 'phpc_cms_v_s_');
                if (false === $bakeSigners) {
                    return self::boxedFalse($context);
                }
            }

            $ok = VmOpensslCmsNative::verify(
                $inputPath,
                $flagsVal,
                $bakeSigners,
                $bakeContent,
                $encodingVal
            );
            if (!$ok) {
                return self::boxedFalse($context);
            }

            if (null !== $bakeContent && is_file($bakeContent)) {
                $contentBytes = (string) file_get_contents($bakeContent);
            }
            if (null !== $bakeSigners && is_file($bakeSigners)) {
                $signersBytes = (string) file_get_contents($bakeSigners);
            }
        } finally {
            if (null !== $bakeContent && is_file($bakeContent)) {
                @unlink($bakeContent);
            }
            if (null !== $bakeSigners && is_file($bakeSigners)) {
                @unlink($bakeSigners);
            }
        }

        $writes = [];
        if (null !== $contentPath[0] && null !== $contentBytes) {
            $writes[] = [$contentPath[0], $contentBytes];
        }
        if (null !== $signersPath[0] && null !== $signersBytes) {
            $writes[] = [$signersPath[0], $signersBytes];
        }

        if ([] === $writes) {
            return self::boxedBool($context, true);
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_cms_verify_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_cms_verify_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_cms_verify_done_'.$id);

        $failed = null;
        foreach ($writes as $idx => [$path, $data]) {
            $pathStr = $context->builder->load($context->constantStringFromString($path));
            $dataStr = $context->builder->load($context->constantStringFromString($data));
            $dataOwned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $dataStr
            );
            $written = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataOwned,
                $i64->constInt(0, false)
            );
            $thisFail = $context->builder->icmp(
                \PHPLLVM\Builder::INT_SLT,
                $written,
                $i64->constInt(0, false)
            );
            $failed = null === $failed
                ? $thisFail
                : $context->builder->or($failed, $thisFail);
            unset($idx);
        }

        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_cms_sign() — bake {@see VmOpensslCmsNative::sign}, write CMS via
     * {@see StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_sign) / CMS_sign
     *
     * Input/output paths, certificate PEM (or path), private-key PEM (or path), headers,
     * flags, and encoding must be compile-time literals (thin AOT has no PHP FFI). Sign runs
     * in the compiler process; the signed blob is emitted at runtime (peer {@see self::cmsVerify}).
     *
     * Non-empty $headers are supported only as a compile-time list of header-line strings
     * (numeric keys → null name, matching VM {@see VmOpenssl::coercePkcs7Headers}).
     */
    public static function cmsSign(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        JITVariable $privateKey,
        JITVariable $headers,
        ?JITVariable $flags = null,
        ?JITVariable $encoding = null,
        ?JITVariable $untrusted = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_cms_sign() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33467)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_cms_sign() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33467)'
            );
        }
        $certMaterial = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certMaterial) {
            throw new \LogicException(
                'openssl_cms_sign() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33467)'
            );
        }
        $keyMaterial = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyMaterial) {
            throw new \LogicException(
                'openssl_cms_sign() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33467)'
            );
        }

        $headerLines = self::compileTimeStringList($headers);
        if (null === $headerLines) {
            if (!($headers->compileTimeEmptyArrayLiteral ?? false)
                && !self::compileTimeOptionsOk($headers)
            ) {
                throw new \LogicException(
                    'openssl_cms_sign() headers must be a compile-time array '
                    .'for JIT/AOT in this compiler build (issue #33467)'
                );
            }
            $headerLines = [];
        }
        /** @var list<array{0: ?string, 1: string}> $bakedHeaders */
        $bakedHeaders = [];
        foreach ($headerLines as $line) {
            $bakedHeaders[] = [null, $line];
        }

        $flagsVal = 0;
        if (null !== $flags && !NamedOptionalCallArgs::isOmittedOptional($flags)) {
            $parsed = self::compileTimeInt($flags);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_cms_sign() flags must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33467)'
                );
            }
            $flagsVal = $parsed;
        }

        $encodingVal = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if (null !== $encoding && !NamedOptionalCallArgs::isOmittedOptional($encoding)) {
            $parsedEnc = self::compileTimeInt($encoding);
            if (null === $parsedEnc) {
                throw new \LogicException(
                    'openssl_cms_sign() encoding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33467)'
                );
            }
            $encodingVal = $parsedEnc;
        }

        if (null !== $untrusted && !NamedOptionalCallArgs::isOmittedOptional($untrusted)) {
            if (null === self::compileTimeNullableString($untrusted)) {
                throw new \LogicException(
                    'openssl_cms_sign() untrusted_certificates_filename must be a compile-time string or null '
                    .'for JIT/AOT in this compiler build (issue #33467)'
                );
            }
        }

        if (!VmOpensslCmsNative::available()) {
            return self::boxedFalse($context);
        }

        if (OpensslConstants::OPENSSL_ENCODING_SMIME === $encodingVal
            && 0 !== ($flagsVal & OpensslConstants::OPENSSL_CMS_DETACHED)
        ) {
            return self::boxedFalse($context);
        }

        $certPem = VmOpenssl::resolvePemMaterial($certMaterial, 'openssl_cms_sign');
        $keyPem = VmOpenssl::resolvePemMaterial($keyMaterial, 'openssl_cms_sign');
        if (false === $certPem || false === $keyPem) {
            return self::boxedFalse($context);
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_cms_sign_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $signedBytes = null;
        try {
            $ok = VmOpensslCmsNative::sign(
                $inputPath,
                $bakeOut,
                $certPem,
                $keyPem,
                $bakedHeaders,
                $flagsVal,
                $encodingVal
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $signedBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_cms_sign_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_cms_sign_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_cms_sign_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($signedBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_cms_encrypt() — bake {@see VmOpensslCmsNative::encrypt}, write CMS via
     * {@see StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_encrypt) / CMS_encrypt
     *
     * Input/output paths, recipient certificate PEM (or path) / list, headers, flags,
     * encoding, and cipher must be compile-time literals (thin AOT has no PHP FFI). Encrypt
     * runs in the compiler process; the ciphertext is emitted at runtime (peer {@see self::cmsSign}).
     *
     * Non-empty $headers are supported only as a compile-time list of header-line strings
     * (numeric keys → null name, matching VM {@see VmOpenssl::coercePkcs7Headers}).
     */
    public static function cmsEncrypt(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        ?JITVariable $headers = null,
        ?JITVariable $flags = null,
        ?JITVariable $encoding = null,
        ?JITVariable $cipherAlgo = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_cms_encrypt() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33473)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_cms_encrypt() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33473)'
            );
        }

        $certMaterials = [];
        $singleCert = JitStringArg::compileTimeLiteral($certificate);
        if (null !== $singleCert) {
            $certMaterials = [$singleCert];
        } else {
            $certList = self::compileTimeStringList($certificate);
            if (null === $certList || [] === $certList) {
                throw new \LogicException(
                    'openssl_cms_encrypt() certificate must be a compile-time string or string list '
                    .'for JIT/AOT in this compiler build (issue #33473)'
                );
            }
            $certMaterials = $certList;
        }

        $bakedHeaders = [];
        if (null !== $headers && !NamedOptionalCallArgs::isOmittedOptional($headers)
            && JITVariable::TYPE_NULL !== $headers->type
            && !($headers->isNullConstant ?? false)
        ) {
            $headerLines = self::compileTimeStringList($headers);
            if (null === $headerLines) {
                if (!($headers->compileTimeEmptyArrayLiteral ?? false)
                    && !self::compileTimeOptionsOk($headers)
                ) {
                    throw new \LogicException(
                        'openssl_cms_encrypt() headers must be a compile-time array or null '
                        .'for JIT/AOT in this compiler build (issue #33473)'
                    );
                }
                $headerLines = [];
            }
            foreach ($headerLines as $line) {
                $bakedHeaders[] = [null, $line];
            }
        }

        $flagsVal = 0;
        if (null !== $flags && !NamedOptionalCallArgs::isOmittedOptional($flags)) {
            $parsed = self::compileTimeInt($flags);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_cms_encrypt() flags must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33473)'
                );
            }
            $flagsVal = $parsed;
        }

        $encodingVal = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if (null !== $encoding && !NamedOptionalCallArgs::isOmittedOptional($encoding)) {
            $parsedEnc = self::compileTimeInt($encoding);
            if (null === $parsedEnc) {
                throw new \LogicException(
                    'openssl_cms_encrypt() encoding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33473)'
                );
            }
            $encodingVal = $parsedEnc;
        }

        $cipherVal = OpensslConstants::OPENSSL_CIPHER_AES_128_CBC;
        if (null !== $cipherAlgo && !NamedOptionalCallArgs::isOmittedOptional($cipherAlgo)) {
            $parsedCipher = self::compileTimeInt($cipherAlgo);
            if (null === $parsedCipher) {
                throw new \LogicException(
                    'openssl_cms_encrypt() cipher_algo must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33473)'
                );
            }
            $cipherVal = $parsedCipher;
        }

        if (!VmOpensslCmsNative::available()) {
            return self::boxedFalse($context);
        }

        $resolved = [];
        foreach ($certMaterials as $material) {
            $pem = VmOpenssl::resolvePemMaterial($material, 'openssl_cms_encrypt');
            if (false === $pem) {
                return self::boxedFalse($context);
            }
            $resolved[] = $pem;
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_cms_enc_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $cipherBytes = null;
        try {
            $ok = VmOpensslCmsNative::encrypt(
                $inputPath,
                $bakeOut,
                $resolved,
                $bakedHeaders,
                $flagsVal,
                $encodingVal,
                $cipherVal
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $cipherBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_cms_enc_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_cms_enc_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_cms_enc_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($cipherBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_cms_decrypt() — bake {@see VmOpensslCmsNative::decrypt}, write plaintext via
     * {@see StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_cms_decrypt) / CMS_decrypt
     *
     * Input/output paths, certificate PEM (or path), private-key PEM (or path), and encoding
     * must be compile-time literals (thin AOT has no PHP FFI). Decrypt runs in the compiler
     * process; plaintext is emitted at runtime (peer {@see self::cmsEncrypt}).
     */
    public static function cmsDecrypt(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        ?JITVariable $privateKey = null,
        ?JITVariable $encoding = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_cms_decrypt() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33479)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_cms_decrypt() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33479)'
            );
        }
        $certMaterial = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certMaterial) {
            throw new \LogicException(
                'openssl_cms_decrypt() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33479)'
            );
        }

        $keyMaterial = $certMaterial;
        if (null !== $privateKey && !NamedOptionalCallArgs::isOmittedOptional($privateKey)) {
            $parsedKey = JitStringArg::compileTimeLiteral($privateKey);
            if (null === $parsedKey) {
                throw new \LogicException(
                    'openssl_cms_decrypt() private_key must be a compile-time string literal '
                    .'for JIT/AOT in this compiler build (issue #33479)'
                );
            }
            $keyMaterial = $parsedKey;
        }

        $encodingVal = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if (null !== $encoding && !NamedOptionalCallArgs::isOmittedOptional($encoding)) {
            $parsedEnc = self::compileTimeInt($encoding);
            if (null === $parsedEnc) {
                throw new \LogicException(
                    'openssl_cms_decrypt() encoding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33479)'
                );
            }
            $encodingVal = $parsedEnc;
        }

        if (!VmOpensslCmsNative::available()) {
            return self::boxedFalse($context);
        }

        $certPem = VmOpenssl::resolvePemMaterial($certMaterial, 'openssl_cms_decrypt');
        $keyPem = VmOpenssl::resolvePemMaterial($keyMaterial, 'openssl_cms_decrypt');
        if (false === $certPem || false === $keyPem) {
            return self::boxedFalse($context);
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_cms_dec_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $plainBytes = null;
        try {
            $ok = VmOpensslCmsNative::decrypt(
                $inputPath,
                $bakeOut,
                $certPem,
                $keyPem,
                $encodingVal
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $plainBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_cms_dec_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_cms_dec_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_cms_dec_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($plainBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkcs7_sign() — bake {@see VmOpensslPkcs7Native::sign}, write PKCS#7 via
     * {@see StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_sign) / PKCS7_sign /
     * SMIME_write_PKCS7
     *
     * Input/output paths, certificate PEM (or path), private-key PEM (or path), headers,
     * and flags must be compile-time literals (thin AOT has no PHP FFI). Sign runs in the
     * compiler process; the signed blob is emitted at runtime (peer {@see self::cmsSign}).
     *
     * Default flags match VM: PKCS7_DETACHED when omitted. Optional untrusted path is
     * type-checked only (VM {@see VmOpenssl::pkcs7Sign} ignores it).
     */
    public static function pkcs7Sign(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        JITVariable $privateKey,
        JITVariable $headers,
        ?JITVariable $flags = null,
        ?JITVariable $untrusted = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_pkcs7_sign() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33471)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_pkcs7_sign() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33471)'
            );
        }
        $certMaterial = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certMaterial) {
            throw new \LogicException(
                'openssl_pkcs7_sign() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33471)'
            );
        }
        $keyMaterial = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyMaterial) {
            throw new \LogicException(
                'openssl_pkcs7_sign() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33471)'
            );
        }

        $headerLines = self::compileTimeStringList($headers);
        if (null === $headerLines) {
            if (!($headers->compileTimeEmptyArrayLiteral ?? false)
                && !self::compileTimeOptionsOk($headers)
            ) {
                throw new \LogicException(
                    'openssl_pkcs7_sign() headers must be a compile-time array '
                    .'for JIT/AOT in this compiler build (issue #33471)'
                );
            }
            $headerLines = [];
        }
        /** @var list<array{0: ?string, 1: string}> $bakedHeaders */
        $bakedHeaders = [];
        foreach ($headerLines as $line) {
            $bakedHeaders[] = [null, $line];
        }

        $flagsVal = OpensslConstants::PKCS7_DETACHED;
        if (null !== $flags && !NamedOptionalCallArgs::isOmittedOptional($flags)) {
            $parsed = self::compileTimeInt($flags);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_pkcs7_sign() flags must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33471)'
                );
            }
            $flagsVal = $parsed;
        }

        if (null !== $untrusted && !NamedOptionalCallArgs::isOmittedOptional($untrusted)) {
            if (null === self::compileTimeNullableString($untrusted)) {
                throw new \LogicException(
                    'openssl_pkcs7_sign() untrusted_certificates_filename must be a compile-time string or null '
                    .'for JIT/AOT in this compiler build (issue #33471)'
                );
            }
        }

        if (!VmOpensslPkcs7Native::available()) {
            return self::boxedFalse($context);
        }

        $certPem = VmOpenssl::resolvePemMaterial($certMaterial, 'openssl_pkcs7_sign');
        $keyPem = VmOpenssl::resolvePemMaterial($keyMaterial, 'openssl_pkcs7_sign');
        if (false === $certPem || false === $keyPem) {
            return self::boxedFalse($context);
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_pkcs7_sign_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $signedBytes = null;
        try {
            $ok = VmOpensslPkcs7Native::sign(
                $inputPath,
                $bakeOut,
                $certPem,
                $keyPem,
                $bakedHeaders,
                $flagsVal
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $signedBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_sign_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_sign_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_sign_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($signedBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkcs7_verify() — bake {@see VmOpensslPkcs7Native::verify}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_verify) / PKCS7_verify
     *
     * Input path, flags, and optional content/signers paths must be compile-time literals
     * (thin AOT has no PHP FFI). Verify runs in the compiler process; content and signers
     * PEMs are emitted via {@see StringFilePutContents} at runtime when those paths are
     * provided (peer {@see self::cmsVerify} / {@see self::exportToFile}).
     *
     * $ca_info / $untrusted / unused trailing path args are type-checked only (VM peer
     * ignores them in {@see VmOpenssl::pkcs7Verify}).
     *
     * Return shape matches php-src: true / false / -1 (error).
     */
    public static function pkcs7Verify(
        Context $context,
        JITVariable $input,
        JITVariable $flags,
        ?JITVariable $signersCertificates = null,
        ?JITVariable $caInfo = null,
        ?JITVariable $untrusted = null,
        ?JITVariable $content = null,
        ?JITVariable $outputFilename = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_pkcs7_verify() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33466)'
            );
        }

        $flagsVal = self::compileTimeInt($flags);
        if (null === $flagsVal) {
            throw new \LogicException(
                'openssl_pkcs7_verify() flags must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #33466)'
            );
        }

        $signersPath = self::compileTimeNullableString(
            (null !== $signersCertificates && !NamedOptionalCallArgs::isOmittedOptional($signersCertificates))
                ? $signersCertificates
                : null
        );
        if (null === $signersPath) {
            throw new \LogicException(
                'openssl_pkcs7_verify() signers_certificates_filename must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #33466)'
            );
        }

        if (null !== $caInfo && !NamedOptionalCallArgs::isOmittedOptional($caInfo)
            && !self::compileTimeOptionsOk($caInfo)
            && null === self::compileTimeStringList($caInfo)
        ) {
            throw new \LogicException(
                'openssl_pkcs7_verify() ca_info must be a compile-time array '
                .'for JIT/AOT in this compiler build (issue #33466)'
            );
        }

        if (null !== $untrusted && !NamedOptionalCallArgs::isOmittedOptional($untrusted)) {
            $untrustedPath = self::compileTimeNullableString($untrusted);
            if (null === $untrustedPath) {
                throw new \LogicException(
                    'openssl_pkcs7_verify() untrusted_certificates_filename must be a compile-time string or null '
                    .'for JIT/AOT in this compiler build (issue #33466)'
                );
            }
        }

        $contentPath = self::compileTimeNullableString(
            (null !== $content && !NamedOptionalCallArgs::isOmittedOptional($content))
                ? $content
                : null
        );
        if (null === $contentPath) {
            throw new \LogicException(
                'openssl_pkcs7_verify() content must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #33466)'
            );
        }

        if (null !== $outputFilename && !NamedOptionalCallArgs::isOmittedOptional($outputFilename)) {
            if (null === self::compileTimeNullableString($outputFilename)) {
                throw new \LogicException(
                    'openssl_pkcs7_verify() output_filename must be a compile-time string or null '
                    .'for JIT/AOT in this compiler build (issue #33466)'
                );
            }
        }

        if (!VmOpensslPkcs7Native::available()) {
            return self::boxedLong($context, -1);
        }

        $bakeContent = null;
        $bakeSigners = null;
        $contentBytes = null;
        $signersBytes = null;
        try {
            if (null !== $contentPath[0]) {
                $bakeContent = tempnam(sys_get_temp_dir(), 'phpc_p7_v_c_');
                if (false === $bakeContent) {
                    return self::boxedLong($context, -1);
                }
            }
            if (null !== $signersPath[0]) {
                $bakeSigners = tempnam(sys_get_temp_dir(), 'phpc_p7_v_s_');
                if (false === $bakeSigners) {
                    return self::boxedLong($context, -1);
                }
            }

            $result = VmOpensslPkcs7Native::verify(
                $inputPath,
                $flagsVal,
                $bakeSigners,
                $bakeContent
            );
            if (-1 === $result) {
                return self::boxedLong($context, -1);
            }
            if (true !== $result) {
                return self::boxedBool($context, false);
            }

            if (null !== $bakeContent && is_file($bakeContent)) {
                $contentBytes = (string) file_get_contents($bakeContent);
            }
            if (null !== $bakeSigners && is_file($bakeSigners)) {
                $signersBytes = (string) file_get_contents($bakeSigners);
            }
        } finally {
            if (null !== $bakeContent && is_file($bakeContent)) {
                @unlink($bakeContent);
            }
            if (null !== $bakeSigners && is_file($bakeSigners)) {
                @unlink($bakeSigners);
            }
        }

        $writes = [];
        if (null !== $contentPath[0] && null !== $contentBytes) {
            $writes[] = [$contentPath[0], $contentBytes];
        }
        if (null !== $signersPath[0] && null !== $signersBytes) {
            $writes[] = [$signersPath[0], $signersBytes];
        }

        if ([] === $writes) {
            return self::boxedBool($context, true);
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_verify_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_verify_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_verify_done_'.$id);

        $failed = null;
        foreach ($writes as $idx => [$path, $data]) {
            $pathStr = $context->builder->load($context->constantStringFromString($path));
            $dataStr = $context->builder->load($context->constantStringFromString($data));
            $dataOwned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $dataStr
            );
            $written = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataOwned,
                $i64->constInt(0, false)
            );
            $thisFail = $context->builder->icmp(
                \PHPLLVM\Builder::INT_SLT,
                $written,
                $i64->constInt(0, false)
            );
            $failed = null === $failed
                ? $thisFail
                : $context->builder->or($failed, $thisFail);
            unset($idx);
        }

        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkcs7_encrypt() — bake {@see VmOpensslPkcs7Native::encrypt}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_encrypt) / PKCS7_encrypt
     * / SMIME_write_PKCS7
     *
     * Input/output paths, recipient cert(s), headers, flags, and cipher must be
     * compile-time literals (thin AOT has no PHP FFI). Encrypt runs in the compiler
     * process; ciphertext is emitted via {@see StringFilePutContents} at runtime
     * (peer {@see self::cmsSign} / {@see self::pkcs7Verify}).
     *
     * $certificate accepts a single string literal or a compile-time string list
     * (php-src OpenSSLCertificate|array|string).
     */
    public static function pkcs7Encrypt(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        JITVariable $headers,
        ?JITVariable $flags = null,
        ?JITVariable $cipherAlgo = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_pkcs7_encrypt() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33474)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_pkcs7_encrypt() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33474)'
            );
        }

        $certMaterials = [];
        $singleCert = JitStringArg::compileTimeLiteral($certificate);
        if (null !== $singleCert) {
            $certMaterials = [$singleCert];
        } else {
            $list = self::compileTimeStringList($certificate);
            if (null === $list || [] === $list) {
                throw new \LogicException(
                    'openssl_pkcs7_encrypt() certificate must be a compile-time string literal or string array '
                    .'for JIT/AOT in this compiler build (issue #33474)'
                );
            }
            $certMaterials = $list;
        }

        $headerLines = self::compileTimeStringList($headers);
        if (null === $headerLines) {
            if (!($headers->compileTimeEmptyArrayLiteral ?? false)
                && !self::compileTimeOptionsOk($headers)
            ) {
                throw new \LogicException(
                    'openssl_pkcs7_encrypt() headers must be a compile-time array '
                    .'for JIT/AOT in this compiler build (issue #33474)'
                );
            }
            $headerLines = [];
        }
        /** @var list<array{0: ?string, 1: string}> $bakedHeaders */
        $bakedHeaders = [];
        foreach ($headerLines as $line) {
            $bakedHeaders[] = [null, $line];
        }

        $flagsVal = 0;
        if (null !== $flags && !NamedOptionalCallArgs::isOmittedOptional($flags)) {
            $parsed = self::compileTimeInt($flags);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_pkcs7_encrypt() flags must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33474)'
                );
            }
            $flagsVal = $parsed;
        }

        $cipherId = OpensslConstants::OPENSSL_CIPHER_AES_128_CBC;
        if (null !== $cipherAlgo && !NamedOptionalCallArgs::isOmittedOptional($cipherAlgo)) {
            $parsedCipher = self::compileTimeInt($cipherAlgo);
            if (null === $parsedCipher) {
                throw new \LogicException(
                    'openssl_pkcs7_encrypt() cipher_algo must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #33474)'
                );
            }
            $cipherId = $parsedCipher;
        }

        if (!VmOpensslPkcs7Native::available()) {
            return self::boxedFalse($context);
        }

        $resolved = [];
        foreach ($certMaterials as $material) {
            $pem = VmOpenssl::resolvePemMaterial($material, 'openssl_pkcs7_encrypt');
            if (false === $pem) {
                return self::boxedFalse($context);
            }
            $resolved[] = $pem;
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_pkcs7_enc_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $encryptedBytes = null;
        try {
            $ok = VmOpensslPkcs7Native::encrypt(
                $inputPath,
                $bakeOut,
                $resolved,
                $bakedHeaders,
                $flagsVal,
                $cipherId
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $encryptedBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_enc_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_enc_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_enc_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($encryptedBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkcs7_decrypt() — bake {@see VmOpensslPkcs7Native::decrypt}, write plaintext via
     * {@see StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkcs7_decrypt) / PKCS7_decrypt
     *
     * Input/output paths, certificate PEM (or path), and private-key PEM (or path) must be
     * compile-time literals (thin AOT has no PHP FFI). Decrypt runs in the compiler process;
     * plaintext is emitted at runtime (peer {@see self::cmsDecrypt} / {@see self::pkcs7Encrypt}).
     *
     * When private_key is omitted, the certificate material is used as the key (VM arity).
     */
    public static function pkcs7Decrypt(
        Context $context,
        JITVariable $input,
        JITVariable $output,
        JITVariable $certificate,
        ?JITVariable $privateKey = null
    ): Value {
        $inputPath = JitStringArg::compileTimeLiteral($input);
        if (null === $inputPath) {
            throw new \LogicException(
                'openssl_pkcs7_decrypt() input_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33482)'
            );
        }
        $outputPath = JitStringArg::compileTimeLiteral($output);
        if (null === $outputPath) {
            throw new \LogicException(
                'openssl_pkcs7_decrypt() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33482)'
            );
        }
        $certMaterial = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certMaterial) {
            throw new \LogicException(
                'openssl_pkcs7_decrypt() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #33482)'
            );
        }

        $keyMaterial = $certMaterial;
        if (null !== $privateKey && !NamedOptionalCallArgs::isOmittedOptional($privateKey)) {
            $parsedKey = JitStringArg::compileTimeLiteral($privateKey);
            if (null === $parsedKey) {
                throw new \LogicException(
                    'openssl_pkcs7_decrypt() private_key must be a compile-time string literal '
                    .'for JIT/AOT in this compiler build (issue #33482)'
                );
            }
            $keyMaterial = $parsedKey;
        }

        if (!VmOpensslPkcs7Native::available()) {
            return self::boxedFalse($context);
        }

        $certPem = VmOpenssl::resolvePemMaterial($certMaterial, 'openssl_pkcs7_decrypt');
        $keyPem = VmOpenssl::resolvePemMaterial($keyMaterial, 'openssl_pkcs7_decrypt');
        if (false === $certPem || false === $keyPem) {
            return self::boxedFalse($context);
        }

        $bakeOut = tempnam(sys_get_temp_dir(), 'phpc_pkcs7_dec_');
        if (false === $bakeOut) {
            return self::boxedFalse($context);
        }

        $plainBytes = null;
        try {
            $ok = VmOpensslPkcs7Native::decrypt(
                $inputPath,
                $bakeOut,
                $certPem,
                $keyPem
            );
            if (!$ok || !is_file($bakeOut)) {
                return self::boxedFalse($context);
            }
            $plainBytes = (string) file_get_contents($bakeOut);
        } finally {
            if (is_file($bakeOut)) {
                @unlink($bakeOut);
            }
        }

        StringFilePutContents::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_dec_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_dec_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkcs7_dec_done_'.$id);

        $pathStr = $context->builder->load($context->constantStringFromString($outputPath));
        $dataStr = $context->builder->load($context->constantStringFromString($plainBytes));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $i64->constInt(0, false)
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_pkcs12_export() — bake {@see VmOpensslPkcs12Native::createPkcs12} into &$output.
     *
     * php-src: ext/openssl/pkcs12.c PHP_FUNCTION(openssl_pkcs12_export) / PKCS12_create
     * By-ref $output is written via __value__writeString (peer {@see self::pkeyExport}).
     *
     * Cert PEM, private-key PEM, and passphrase must be compile-time string literals.
     * Options must be omitted/null (thin AOT does not bake friendly_name/extracerts).
     * PKCS#12 MAC salt is non-deterministic — baked blob is fixed for that compile.
     */
    public static function pkcs12Export(
        Context $context,
        JITVariable $certificate,
        JITVariable $output,
        JITVariable $privateKey,
        JITVariable $passphrase,
        ?JITVariable $options = null
    ): Value {
        $certPem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certPem) {
            throw new \LogicException(
                'openssl_pkcs12_export() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_pkcs12_export() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        $pass = JitStringArg::compileTimeLiteral($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkcs12_export() passphrase must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        if (null !== $options && JITVariable::TYPE_NULL !== $options->type && !($options->isNullConstant ?? false)) {
            throw new \LogicException(
                'openssl_pkcs12_export() options must be omitted or null '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }

        if (!VmOpensslPkcs12Native::available()) {
            return self::boxedFalse($context);
        }

        $blob = VmOpensslPkcs12Native::createPkcs12($certPem, $keyPem, $pass);
        if (false === $blob) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
        $str = $context->builder->load($context->constantStringFromString($blob));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_pkcs12_export_to_file() — bake {@see VmOpensslPkcs12Native::createPkcs12}, write via
     * {@see \PHPCompiler\JIT\Builtin\StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/pkcs12.c PHP_FUNCTION(openssl_pkcs12_export_to_file)
     */
    public static function pkcs12ExportToFile(
        Context $context,
        JITVariable $certificate,
        JITVariable $outputFilename,
        JITVariable $privateKey,
        JITVariable $passphrase,
        ?JITVariable $options = null
    ): Value {
        $certPem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certPem) {
            throw new \LogicException(
                'openssl_pkcs12_export_to_file() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        $path = JitStringArg::compileTimeLiteral($outputFilename);
        if (null === $path) {
            throw new \LogicException(
                'openssl_pkcs12_export_to_file() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_pkcs12_export_to_file() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        $pass = JitStringArg::compileTimeLiteral($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkcs12_export_to_file() passphrase must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }
        if (null !== $options && JITVariable::TYPE_NULL !== $options->type && !($options->isNullConstant ?? false)) {
            throw new \LogicException(
                'openssl_pkcs12_export_to_file() options must be omitted or null '
                .'for JIT/AOT in this compiler build (issue #32948)'
            );
        }

        if (!VmOpensslPkcs12Native::available()) {
            return self::boxedFalse($context);
        }

        $blob = VmOpensslPkcs12Native::createPkcs12($certPem, $keyPem, $pass);
        if (false === $blob) {
            return self::boxedFalse($context);
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));
        $dataStr = $context->builder->load($context->constantStringFromString($blob));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkcs12_export_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkcs12_export_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkcs12_export_file_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * openssl_seal() — bake {@see VmOpensslSealNative::seal} into &$sealed_data / &$encrypted_keys / &$iv.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_seal) (EVP_Seal semantics)
     * Data, public-key PEM list, and cipher_algo must be compile-time literals. Session key /
     * IV are non-deterministic — baked outputs are fixed for that compile (peer #32713).
     *
     * @return Value boxed int length, or false
     */
    public static function seal(
        Context $context,
        JITVariable $data,
        JITVariable $sealedData,
        JITVariable $encryptedKeys,
        JITVariable $publicKeys,
        JITVariable $cipherAlgo,
        ?JITVariable $iv = null
    ): Value {
        $plain = JitStringArg::compileTimeLiteral($data);
        if (null === $plain) {
            throw new \LogicException(
                'openssl_seal() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $pubs = self::compileTimeStringList($publicKeys);
        if (null === $pubs) {
            throw new \LogicException(
                'openssl_seal() public_key must be a compile-time array of string literals '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $cipher = JitStringArg::compileTimeLiteral($cipherAlgo);
        if (null === $cipher) {
            throw new \LogicException(
                'openssl_seal() cipher_algo must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $assignIv = null !== $iv;

        if (!VmOpensslSealNative::available()) {
            return self::boxedFalse($context);
        }

        $result = VmOpensslSealNative::seal($plain, $pubs, $cipher, $assignIv);
        if (false === $result) {
            return self::boxedFalse($context);
        }

        $sealedPtr = JitValueBox::valuePtrFromVariable($context, $sealedData);
        $sealedStr = $context->builder->load($context->constantStringFromString($result['sealed']));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $sealedPtr,
            $sealedStr
        );
        JitValueBox::publishAfterWrite($context, $sealedPtr);

        $ekeysHt = new \PHPCompiler\VM\HashTable();
        foreach ($result['encrypted_keys'] as $encryptedKey) {
            $ekeyVar = new \PHPCompiler\VM\Variable();
            $ekeyVar->string($encryptedKey);
            $ekeysHt->append($ekeyVar);
        }
        $ekeysJit = HashTableHelper::variableFromVmHashTable($context, $ekeysHt);
        $ekeysOutPtr = JitValueBox::valuePtrFromVariable($context, $encryptedKeys);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ekeysOutPtr,
            $context->helper->loadValue($ekeysJit)
        );
        JitValueBox::publishAfterWrite($context, $ekeysOutPtr);

        if ($assignIv) {
            $ivPtr = JitValueBox::valuePtrFromVariable($context, $iv);
            $ivStr = $context->builder->load($context->constantStringFromString($result['iv']));
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ivPtr,
                $ivStr
            );
            JitValueBox::publishAfterWrite($context, $ivPtr);
        }

        return self::boxedLong($context, $result['length']);
    }

    /**
     * openssl_open() — bake {@see VmOpensslSealNative::open} into &$output.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_open) (EVP_Open semantics)
     * Sealed data, encrypted key, private-key PEM, cipher_algo, and optional IV must be
     * compile-time string literals (thin AOT has no PHP FFI).
     */
    public static function open(
        Context $context,
        JITVariable $data,
        JITVariable $output,
        JITVariable $encryptedKey,
        JITVariable $privateKey,
        JITVariable $cipherAlgo,
        ?JITVariable $iv = null
    ): Value {
        $sealed = JitStringArg::compileTimeLiteral($data);
        if (null === $sealed) {
            throw new \LogicException(
                'openssl_open() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $ekey = JitStringArg::compileTimeLiteral($encryptedKey);
        if (null === $ekey) {
            throw new \LogicException(
                'openssl_open() encrypted_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $pem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_open() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $cipher = JitStringArg::compileTimeLiteral($cipherAlgo);
        if (null === $cipher) {
            throw new \LogicException(
                'openssl_open() cipher_algo must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32979)'
            );
        }
        $ivLit = null;
        if (null !== $iv) {
            if (JITVariable::TYPE_NULL === $iv->type || ($iv->isNullConstant ?? false)) {
                $ivLit = null;
            } else {
                $ivLit = JitStringArg::compileTimeLiteral($iv);
                if (null === $ivLit) {
                    throw new \LogicException(
                        'openssl_open() iv must be a compile-time string literal or null '
                        .'for JIT/AOT in this compiler build (issue #32979)'
                    );
                }
            }
        }

        if (!VmOpensslSealNative::available()) {
            return self::boxedFalse($context);
        }

        $plain = VmOpensslSealNative::open($sealed, $ekey, $pem, $cipher, $ivLit);
        if (false === $plain) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
        $str = $context->builder->load($context->constantStringFromString($plain));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_spki_verify() — bake {@see VmOpensslSpkiNative::spkiVerify}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_spki_verify) / NETSCAPE_SPKI_verify
     * SPKAC argument is the base64 payload (with or without {@code SPKAC=} prefix); VM
     * {@see VmOpenssl::spkiVerify} normalizes via {@see VmOpensslSpkiNative::spkiCleanup}.
     */
    public static function spkiVerify(Context $context, JITVariable $spkac): Value
    {
        $lit = JitStringArg::compileTimeLiteral($spkac);
        if (null === $lit) {
            throw new \LogicException(
                'openssl_spki_verify() spkac must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32776)'
            );
        }

        if (!VmOpensslSpkiNative::available()) {
            return self::boxedBool($context, false);
        }

        return self::boxedBool($context, VmOpensslSpkiNative::spkiVerify($lit));
    }

    /**
     * openssl_spki_export() — bake {@see VmOpensslSpkiNative::spkiExport}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_spki_export) /
     * NETSCAPE_SPKI_get_pubkey + PEM_write_bio_PUBKEY
     * SPKAC argument is the base64 payload (with or without {@code SPKAC=} prefix); VM
     * {@see VmOpenssl::spkiExport} normalizes via {@see VmOpensslSpkiNative::spkiCleanup}.
     */
    public static function spkiExport(Context $context, JITVariable $spkac): Value
    {
        $lit = JitStringArg::compileTimeLiteral($spkac);
        if (null === $lit) {
            throw new \LogicException(
                'openssl_spki_export() spkac must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32787)'
            );
        }

        if (!VmOpensslSpkiNative::available()) {
            return self::boxedFalse($context);
        }

        $pem = VmOpensslSpkiNative::spkiExport($lit);
        if (false === $pem) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $pem);
    }

    /**
     * openssl_spki_export_challenge() — bake {@see VmOpensslSpkiNative::spkiExportChallenge}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_spki_export_challenge) /
     * NETSCAPE_SPKI_get_challenge
     * SPKAC argument is the base64 payload (with or without {@code SPKAC=} prefix); VM
     * {@see VmOpenssl::spkiExportChallenge} normalizes via {@see VmOpensslSpkiNative::spkiCleanup}.
     */
    public static function spkiExportChallenge(Context $context, JITVariable $spkac): Value
    {
        $lit = JitStringArg::compileTimeLiteral($spkac);
        if (null === $lit) {
            throw new \LogicException(
                'openssl_spki_export_challenge() spkac must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32792)'
            );
        }

        if (!VmOpensslSpkiNative::available()) {
            return self::boxedFalse($context);
        }

        $challenge = VmOpensslSpkiNative::spkiExportChallenge($lit);
        if (false === $challenge) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $challenge);
    }

    /**
     * openssl_x509_fingerprint() — bake {@see VmOpensslX509Native::fingerprintCertificatePem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
     */
    public static function fingerprint(
        Context $context,
        JITVariable $certificate,
        ?JITVariable $digestAlg = null,
        ?JITVariable $binary = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_fingerprint() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32512)'
            );
        }
        $algo = 'sha1';
        if (null !== $digestAlg) {
            $lit = JitStringArg::compileTimeLiteral($digestAlg);
            if (null === $lit) {
                throw new \LogicException(
                    'openssl_x509_fingerprint() digest_alg must be a compile-time string '
                    .'for JIT/AOT in this compiler build (issue #32512)'
                );
            }
            $algo = $lit;
        }
        $raw = self::compileTimeBool($binary, false);
        if (null === $raw) {
            throw new \LogicException(
                'openssl_x509_fingerprint() binary must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32512)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $fingerprint = VmOpensslX509Native::fingerprintCertificatePem($pem, $algo, $raw);
        if (false === $fingerprint) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $fingerprint);
    }

    /**
     * openssl_x509_checkpurpose() — bake {@see VmOpensslX509Native::checkPurposeCertificatePem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_checkpurpose) / check_cert / X509_verify_cert
     * Return matches VM {@see VmOpensslObjects::checkPurpose}: bool for 0/1, int otherwise (-1 error).
     */
    public static function checkPurpose(
        Context $context,
        JITVariable $certificate,
        JITVariable $purpose,
        ?JITVariable $caInfo = null,
        ?JITVariable $untrusted = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_checkpurpose() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32522)'
            );
        }
        $purposeInt = self::compileTimeInt($purpose);
        if (null === $purposeInt) {
            throw new \LogicException(
                'openssl_x509_checkpurpose() purpose must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32522)'
            );
        }
        $caList = [];
        if (null !== $caInfo) {
            $caList = self::compileTimeStringList($caInfo);
            if (null === $caList) {
                throw new \LogicException(
                    'openssl_x509_checkpurpose() ca_info must be a compile-time string array '
                    .'for JIT/AOT in this compiler build (issue #32522)'
                );
            }
        }
        $untrustedFile = null;
        if (null !== $untrusted) {
            if (JITVariable::TYPE_NULL === $untrusted->type || ($untrusted->isNullConstant ?? false)) {
                $untrustedFile = null;
            } else {
                $untrustedFile = JitStringArg::compileTimeLiteral($untrusted);
                if (null === $untrustedFile) {
                    throw new \LogicException(
                        'openssl_x509_checkpurpose() untrusted_certificates_file must be a compile-time string or null '
                        .'for JIT/AOT in this compiler build (issue #32522)'
                    );
                }
            }
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedLong($context, -1);
        }
        if (false === VmOpensslX509Native::normalizeCertificatePem($pem)) {
            return self::boxedLong($context, -1);
        }

        $ret = VmOpensslX509Native::checkPurposeCertificatePem(
            $pem,
            $purposeInt,
            $caList,
            $untrustedFile
        );
        if (0 !== $ret && 1 !== $ret) {
            return self::boxedLong($context, $ret);
        }

        return self::boxedBool($context, 1 === $ret);
    }

    /**
     * openssl_x509_check_private_key() — bake {@see VmOpensslX509Native::checkPrivateKeyPem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_check_private_key) / X509_check_private_key
     * php-src returns false (no warning) when the certificate or private key cannot be loaded.
     */
    public static function checkPrivateKey(
        Context $context,
        JITVariable $certificate,
        JITVariable $privateKey
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_check_private_key() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32527)'
            );
        }
        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_x509_check_private_key() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32527)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }
        if (false === VmOpensslX509Native::normalizeCertificatePem($pem)) {
            return self::boxedFalse($context);
        }

        return self::boxedBool($context, VmOpensslX509Native::checkPrivateKeyPem($pem, $keyPem));
    }

    /**
     * openssl_x509_verify() — bake {@see VmOpensslX509Native::verifyCertificatePem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_verify) / X509_verify
     * VM {@see VmOpensslObjects::verifyCertificate}: int 1/0/-1; bool false when the cert cannot be loaded.
     * A certificate PEM as $public_key is extracted to a PUBKEY PEM like the VM path.
     */
    public static function verify(
        Context $context,
        JITVariable $certificate,
        JITVariable $publicKey
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_verify() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32535)'
            );
        }
        $keyPem = JitStringArg::compileTimeLiteral($publicKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_x509_verify() public_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32535)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }
        if (false === VmOpensslX509Native::normalizeCertificatePem($pem)) {
            return self::boxedFalse($context);
        }

        $pubPem = $keyPem;
        if (str_contains($keyPem, 'BEGIN CERTIFICATE')) {
            $extracted = VmOpensslX509Native::extractPublicKeyPem($keyPem);
            if (false === $extracted) {
                return self::boxedFalse($context);
            }
            $pubPem = $extracted;
        }

        $verified = VmOpensslX509Native::verifyCertificatePem($pem, $pubPem);
        if ($verified < 0) {
            return self::boxedLong($context, -1);
        }

        return self::boxedLong($context, $verified);
    }

    /**
     * openssl_x509_export() — bake {@see VmOpensslX509Native::exportCertificatePem} into &$output.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_export) / PEM_write_bio_X509 / X509_print
     * By-ref $output is written via __value__writeString (peer {@see JitOpensslSign::sign}).
     */
    public static function export(
        Context $context,
        JITVariable $certificate,
        JITVariable $output,
        ?JITVariable $noText = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_export() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32557)'
            );
        }
        $noTextBool = self::compileTimeBool($noText, true);
        if (null === $noTextBool) {
            throw new \LogicException(
                'openssl_x509_export() no_text must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32557)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslX509Native::exportCertificatePem($pem, $noTextBool);
        if (false === $exported) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $output);
        $str = $context->builder->load($context->constantStringFromString($exported));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_x509_export_to_file() — bake {@see VmOpensslX509Native::exportCertificatePem}, write via
     * {@see \PHPCompiler\JIT\Builtin\StringFilePutContents} / __compiler_file_put_contents.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_export_to_file)
     * Certificate PEM and output path must be compile-time string literals (peer export() / SimpleXML asXML).
     */
    public static function exportToFile(
        Context $context,
        JITVariable $certificate,
        JITVariable $outputFilename,
        ?JITVariable $noText = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_export_to_file() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32557)'
            );
        }
        $path = JitStringArg::compileTimeLiteral($outputFilename);
        if (null === $path) {
            throw new \LogicException(
                'openssl_x509_export_to_file() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32557)'
            );
        }
        $noTextBool = self::compileTimeBool($noText, true);
        if (null === $noTextBool) {
            throw new \LogicException(
                'openssl_x509_export_to_file() no_text must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32557)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslX509Native::exportCertificatePem($pem, $noTextBool);
        if (false === $exported) {
            return self::boxedFalse($context);
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));
        $dataStr = $context->builder->load($context->constantStringFromString($exported));
        $dataOwned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $dataStr
        );
        $written = $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $dataOwned,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $written,
            $i64->constInt(0, false)
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_x509_export_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_x509_export_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_x509_export_file_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * Compile-time passphrase: omitted / null / string literal.
     *
     * @return list{?string}|null
     */
    private static function compileTimeNullableString(?JITVariable $arg): ?array
    {
        if (null === $arg) {
            return [null];
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return [null];
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            return null;
        }

        return [$lit];
    }

    private static function compileTimeOptionsOk(?JITVariable $arg): bool
    {
        if (null === $arg) {
            return true;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return true;
        }

        return JITVariable::TYPE_HASHTABLE === $arg->type;
    }

    private static function compileTimeBool(?JITVariable $arg, bool $default): ?bool
    {
        if (null === $arg) {
            return $default;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return '' !== $lit && '0' !== $lit;
        }

        return null;
    }

    private static function compileTimeInt(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            return (int) $lit;
        }

        return null;
    }

    /**
     * Compile-time string list for $ca_info / openssl_seal() $public_key.
     * Accepts TYPE_HASHTABLE, native arrays, or boxed TYPE_VALUE with compileTimeArray
     * (INIT_ARRAY args to builtins are often value-boxed — #32979).
     *
     * @return list<string>|null
     */
    private static function compileTimeStringList(JITVariable $arg): ?array
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        $isArrayShape = JITVariable::TYPE_HASHTABLE === $arg->type
            || JITVariable::TYPE_VALUE === $arg->type
            || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY);
        if (!$isArrayShape) {
            return null;
        }
        if ($arg->compileTimeEmptyArrayLiteral) {
            return [];
        }
        if (!\is_array($arg->compileTimeArray)) {
            return null;
        }
        $out = [];
        foreach ($arg->compileTimeArray as $v) {
            if (!\is_string($v) && !\is_int($v) && !\is_float($v) && !\is_bool($v) && null !== $v) {
                return null;
            }
            $out[] = null === $v ? '' : (string) $v;
        }

        return $out;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($value));
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }

    private static function boxedLong(Context $context, int $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $context->constantFromInteger($value));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool($value));

        return JitValueBox::pointer($context, $slot);
    }
}
