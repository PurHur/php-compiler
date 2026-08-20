<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
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
 * openssl_pkey_export() (#32705 leftover of #6295),
 * openssl_pkey_export_to_file() (#32705 leftover of #20287),
 * openssl_public_encrypt() (#32713 leftover of #6666),
 * openssl_private_encrypt() (#32757 leftover of #6666), and
 * openssl_private_decrypt() (#32759 leftover of #6666).
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
     * openssl_pkey_export() — bake {@see VmOpensslPkeyNative::exportPrivateKeyPem} into &$output.
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
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_pkey_export() key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }
        $pass = self::compileTimeNullableString($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkey_export() passphrase must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }
        if (!self::compileTimeOptionsOk($options)) {
            throw new \LogicException(
                'openssl_pkey_export() options must be a compile-time ?array '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslPkeyNative::exportPrivateKeyPem($pem, $pass[0]);
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
     * openssl_pkey_export_to_file() — bake {@see VmOpensslPkeyNative::exportPrivateKeyPem}, write via
     * {@see \PHPCompiler\JIT\Builtin\StringFilePutContents} / __compiler_file_put_contents.
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
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_pkey_export_to_file() key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }
        $path = JitStringArg::compileTimeLiteral($outputFilename);
        if (null === $path) {
            throw new \LogicException(
                'openssl_pkey_export_to_file() output_filename must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }
        $pass = self::compileTimeNullableString($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkey_export_to_file() passphrase must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }
        if (!self::compileTimeOptionsOk($options)) {
            throw new \LogicException(
                'openssl_pkey_export_to_file() options must be a compile-time ?array '
                .'for JIT/AOT in this compiler build (issue #32705)'
            );
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $exported = VmOpensslPkeyNative::exportPrivateKeyPem($pem, $pass[0]);
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
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_export_file_done_'.$id);
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
     * openssl_public_encrypt() — bake {@see VmOpensslPkeyNative::encrypt} into &$encrypted.
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
        $plain = JitStringArg::compileTimeLiteral($data);
        if (null === $plain) {
            throw new \LogicException(
                'openssl_public_encrypt() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32713)'
            );
        }
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_public_encrypt() key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32713)'
            );
        }
        $pad = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (null !== $padding) {
            $padLit = self::compileTimeInt($padding);
            if (null === $padLit) {
                throw new \LogicException(
                    'openssl_public_encrypt() padding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #32713)'
                );
            }
            $pad = $padLit;
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $cipher = VmOpensslPkeyNative::encrypt($plain, $pem, $pad);
        if (false === $cipher) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $encrypted);
        $str = $context->builder->load($context->constantStringFromString($cipher));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_private_encrypt() — bake {@see VmOpensslPkeyNative::privateEncrypt} into &$encrypted.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_private_encrypt) / EVP_PKEY_sign
     * By-ref $encrypted is written via __value__writeString (peer {@see self::publicEncrypt}).
     *
     * PKCS#1 type-1 private encrypt is deterministic for a fixed key+data; still assert
     * bool + non-empty ciphertext in repros (peer #32713).
     */
    public static function privateEncrypt(
        Context $context,
        JITVariable $data,
        JITVariable $encrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $plain = JitStringArg::compileTimeLiteral($data);
        if (null === $plain) {
            throw new \LogicException(
                'openssl_private_encrypt() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32757)'
            );
        }
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_private_encrypt() key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32757)'
            );
        }
        $pad = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (null !== $padding) {
            $padLit = self::compileTimeInt($padding);
            if (null === $padLit) {
                throw new \LogicException(
                    'openssl_private_encrypt() padding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #32757)'
                );
            }
            $pad = $padLit;
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $cipher = VmOpensslPkeyNative::privateEncrypt($plain, $pem, $pad);
        if (false === $cipher) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $encrypted);
        $str = $context->builder->load($context->constantStringFromString($cipher));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
        JitValueBox::publishAfterWrite($context, $outPtr);

        return self::boxedBool($context, true);
    }

    /**
     * openssl_private_decrypt() — bake {@see VmOpensslPkeyNative::decrypt} into &$decrypted.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_private_decrypt) / EVP_PKEY_decrypt
     * By-ref $decrypted is written via __value__writeString (peer {@see self::publicEncrypt}).
     *
     * Ciphertext and key must be compile-time string literals (thin AOT has no PHP FFI).
     */
    public static function privateDecrypt(
        Context $context,
        JITVariable $data,
        JITVariable $decrypted,
        JITVariable $key,
        ?JITVariable $padding = null
    ): Value {
        $cipher = JitStringArg::compileTimeLiteral($data);
        if (null === $cipher) {
            throw new \LogicException(
                'openssl_private_decrypt() data must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32759)'
            );
        }
        $pem = JitStringArg::compileTimeLiteral($key);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_private_decrypt() key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32759)'
            );
        }
        $pad = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (null !== $padding) {
            $padLit = self::compileTimeInt($padding);
            if (null === $padLit) {
                throw new \LogicException(
                    'openssl_private_decrypt() padding must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #32759)'
                );
            }
            $pad = $padLit;
        }

        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $plain = VmOpensslPkeyNative::decrypt($cipher, $pem, $pad);
        if (false === $plain) {
            return self::boxedFalse($context);
        }

        $outPtr = JitValueBox::valuePtrFromVariable($context, $decrypted);
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
     * Compile-time string list for $ca_info. Empty omitted-arg is handled by the caller.
     *
     * @return list<string>|null
     */
    private static function compileTimeStringList(JITVariable $arg): ?array
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE !== $arg->type) {
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
