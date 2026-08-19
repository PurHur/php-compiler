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
 * openssl_x509_export() (#32557 leftover of #20273), and
 * openssl_x509_export_to_file() (#32557 leftover of #20273).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_parse)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_checkpurpose) / check_cert / X509_verify_cert
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_check_private_key) / X509_check_private_key
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_verify) / X509_verify
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_export) / PEM_write_bio_X509 / X509_print
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_export_to_file)
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
