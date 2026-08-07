<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * OpenSSLCertificate / OpenSSLAsymmetricKey / OpenSSLCertificateSigningRequest lifecycle.
 *
 * php-src: ext/openssl/openssl.stub.php, ext/openssl/xp.c
 */
final class VmOpensslObjects
{
    public const CERT_LC = 'opensslcertificate';

    public const KEY_LC = 'opensslasymmetrickey';

    public const CSR_LC = 'opensslcertificatesigningrequest';

    /** @var array<int, string> object id => PEM material */
    private static array $certStore = [];

    /** @var array<int, string> object id => PEM material */
    private static array $keyStore = [];

    /** @var array<int, string> object id => PEM material */
    private static array $csrStore = [];

    public static function registerClasses(Context $ctx): void
    {
        self::registerInternalClass($ctx, self::CERT_LC, 'OpenSSLCertificate');
        self::registerInternalClass($ctx, self::KEY_LC, 'OpenSSLAsymmetricKey');
        self::registerInternalClass($ctx, self::CSR_LC, 'OpenSSLCertificateSigningRequest');
    }

    /**
     * Withhold openssl object classes from class_exists() until extension_loaded('openssl') (#11859, #16765).
     */
    public static function isHiddenClassEntry(ClassEntry $entry): bool
    {
        if (OpensslExtensionPolicy::advertisesExtension()) {
            return false;
        }
        $lc = strtolower(ltrim($entry->name, '\\'));

        return \in_array($lc, [self::CERT_LC, self::KEY_LC, self::CSR_LC], true);
    }

    public static function isHiddenClassLc(string $classLc): bool
    {
        if (OpensslExtensionPolicy::advertisesExtension()) {
            return false;
        }
        $lc = strtolower(ltrim($classLc, '\\'));

        return \in_array($lc, [self::CERT_LC, self::KEY_LC, self::CSR_LC], true);
    }

    public static function readCertificate(Context $ctx, Variable $arg): Variable
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            if (self::CERT_LC === strtolower($object->class->name)) {
                $result = new Variable(Variable::TYPE_OBJECT);
                $result->object($object);

                return $result;
            }
            throw new \TypeError(\sprintf(
                'openssl_x509_read(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                'openssl_x509_read(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                'openssl_x509_read(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                self::typeLabel($arg)
            ));
        }

        return self::createCertificateFromPem($ctx, $arg->toString());
    }

    public static function createCertificateFromPem(Context $ctx, string $pem): Variable
    {
        $normalized = VmOpensslX509Native::normalizeCertificatePem($pem);
        if (false === $normalized) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        return self::wrapCertificate($ctx, $normalized);
    }

    public static function wrapCertificate(Context $ctx, string $pem): Variable
    {
        $class = $ctx->classes[self::CERT_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('OpenSSLCertificate is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$certStore[$entry->id] = $pem;
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function wrapKey(Context $ctx, string $pem): Variable
    {
        $class = $ctx->classes[self::KEY_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('OpenSSLAsymmetricKey is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$keyStore[$entry->id] = $pem;
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function wrapCsr(Context $ctx, string $pem): Variable
    {
        $class = $ctx->classes[self::CSR_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('OpenSSLCertificateSigningRequest is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$csrStore[$entry->id] = $pem;
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function keyPem(\PHPCompiler\VM\ObjectEntry $entry): string
    {
        return self::$keyStore[$entry->id] ?? '';
    }

    public static function csrPem(ObjectEntry $entry): string
    {
        return self::$csrStore[$entry->id] ?? '';
    }

    public static function isCsr(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }

        return self::CSR_LC === strtolower($var->toObject()->class->name);
    }

    /**
     * Resolve OpenSSLCertificateSigningRequest|string to CSR PEM (php-src openssl_csr_*).
     *
     * @return string|null PEM, or null when caller should return false
     */
    public static function resolveCsrPem(Variable $arg, string $function, int $argIndex = 0): ?string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            if (self::CSR_LC === strtolower($object->class->name)) {
                return self::csrPem($object);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($csr) must be of type OpenSSLCertificateSigningRequest|string, %s given',
                $function,
                $argIndex + 1,
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($csr) must be of type OpenSSLCertificateSigningRequest|string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($csr) must be of type OpenSSLCertificateSigningRequest|string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($arg)
            ));
        }

        return $arg->toString();
    }

    public static function isCertificate(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }

        return self::CERT_LC === strtolower($var->toObject()->class->name);
    }

    public static function isAsymmetricKey(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return false;
        }

        return self::KEY_LC === strtolower($var->toObject()->class->name);
    }

    public static function certificatePem(ObjectEntry $entry): string
    {
        return self::$certStore[$entry->id] ?? '';
    }

    /**
     * openssl_x509_fingerprint() — DER digest of X509 PEM or OpenSSLCertificate (ext/openssl/x509.c; #6524).
     */
    public static function fingerprintCertificate(
        Context $ctx,
        Variable $arg,
        string $hashAlgo,
        bool $rawOutput,
        ?Frame $frame = null,
    ): Variable {
        if (!VmOpensslX509Native::digestAvailable($hashAlgo)) {
            VmOpenssl::userWarningForFrame('openssl_x509_fingerprint(): Unknown digest algorithm', $frame);

            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $pem = self::resolveCertificatePem($ctx, $arg, 'openssl_x509_fingerprint');
        if (null === $pem || false === VmOpensslX509Native::normalizeCertificatePem($pem)) {
            VmOpenssl::userWarningForFrame('openssl_x509_fingerprint(): X.509 Certificate cannot be retrieved', $frame);

            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $fingerprint = VmOpensslX509Native::fingerprintCertificatePem($pem, $hashAlgo, $rawOutput);
        $result = new Variable();
        if (false === $fingerprint) {
            VmOpenssl::userWarningForFrame('openssl_x509_fingerprint(): X.509 Fingerprint cannot be generated', $frame);
            $result->bool(false);

            return $result;
        }
        $result->string($fingerprint);

        return $result;
    }

    /**
     * openssl_x509_check_private_key() — X509_check_private_key (ext/openssl/openssl.c; #20285).
     *
     * php-src returns false silently when the certificate or private key cannot be loaded (no warning).
     */
    public static function checkPrivateKey(
        Context $ctx,
        Variable $certArg,
        Variable $privateKeyArg,
        ?Frame $frame = null,
    ): Variable {
        unset($frame);

        $result = new Variable();
        if (!VmOpensslX509Native::available()) {
            $result->bool(false);

            return $result;
        }

        $certPem = self::resolveCertificatePem($ctx, $certArg, 'openssl_x509_check_private_key');
        if (null === $certPem || false === VmOpensslX509Native::normalizeCertificatePem($certPem)) {
            $result->bool(false);

            return $result;
        }

        $keyPem = self::resolveCheckPrivateKeyPem($privateKeyArg);
        if (null === $keyPem) {
            $result->bool(false);

            return $result;
        }

        $result->bool(VmOpensslX509Native::checkPrivateKeyPem($certPem, $keyPem));

        return $result;
    }

    /**
     * openssl_x509_checkpurpose() — X509_verify_cert with purpose (ext/openssl/openssl.c; #20286).
     *
     * php-src returns -1 (int) on setup/parse failure; bool for 0/1 verify results; other ints unchanged.
     *
     * @param list<string> $caInfo
     */
    public static function checkPurpose(
        Context $ctx,
        Variable $certArg,
        int $purpose,
        array $caInfo,
        ?string $untrustedFile,
        ?Frame $frame = null,
    ): Variable {
        $result = new Variable();
        if (!VmOpensslX509Native::available()) {
            $result->int(-1);

            return $result;
        }

        $certPem = self::resolveCertificatePem($ctx, $certArg, 'openssl_x509_checkpurpose');
        if (null === $certPem || false === VmOpensslX509Native::normalizeCertificatePem($certPem)) {
            $result->int(-1);

            return $result;
        }

        $ret = VmOpensslX509Native::checkPurposeCertificatePem(
            $certPem,
            $purpose,
            $caInfo,
            $untrustedFile,
            $frame
        );
        if (0 !== $ret && 1 !== $ret) {
            $result->int($ret);
        } else {
            $result->bool(1 === $ret);
        }

        return $result;
    }

    /**
     * openssl_x509_verify() — X509_verify against supplied public key (ext/openssl/x509.c; #6595).
     */
    public static function verifyCertificate(
        Context $ctx,
        Variable $certArg,
        Variable $pubKeyArg,
        int $flags,
        ?Frame $frame = null,
    ): Variable {
        unset($flags);

        if (!VmOpensslX509Native::available()) {
            VmOpenssl::userWarningForFrame(
                'openssl_x509_verify(): OpenSSL X.509 verification is unavailable in this compiler build',
                $frame
            );
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $certPem = self::resolveCertificatePem($ctx, $certArg, 'openssl_x509_verify');
        if (null === $certPem || false === VmOpensslX509Native::normalizeCertificatePem($certPem)) {
            VmOpenssl::userWarningForFrame(
                'openssl_x509_verify(): X.509 Certificate cannot be retrieved',
                $frame
            );
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $pubKeyPem = self::resolveVerifyPublicKeyPem($pubKeyArg, 'openssl_x509_verify', 1, $frame);
        if (null === $pubKeyPem) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $verified = VmOpensslX509Native::verifyCertificatePem($certPem, $pubKeyPem);
        $result = new Variable();
        if ($verified < 0) {
            VmOpenssl::userWarningForFrame('openssl_x509_verify(): Signature verification errored', $frame);
            $result->int(-1);

            return $result;
        }
        $result->int($verified);

        return $result;
    }

    /**
     * openssl_x509_parse() — X509 PEM or OpenSSLCertificate to metadata array (ext/openssl/xp.c; #6274).
     */
    public static function parseCertificate(Context $ctx, Variable $arg, bool $shortnames): Variable
    {
        $pem = self::resolveCertificatePem($ctx, $arg, 'openssl_x509_parse');
        if (null === $pem) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        $parsed = VmOpensslX509Native::parseCertificatePem($pem, $shortnames);
        $result = new Variable();
        if (false === $parsed) {
            $result->bool(false);

            return $result;
        }
        $result->copyFrom(self::phpValueToVariable($parsed));

        return $result;
    }

    /**
     * @param array<string, mixed>|string|int|float|bool|null $value
     */
    public static function variableFromPhpValue(array|string|int|float|bool|null $value): Variable
    {
        return self::phpValueToVariable($value);
    }

    /**
     * @param array<string, mixed>|string|int|float|bool|null $value
     */
    private static function phpValueToVariable(array|string|int|float|bool|null $value): Variable
    {
        $var = new Variable();
        if (\is_array($value)) {
            $ht = new HashTable();
            foreach ($value as $key => $item) {
                $ht->update((string) $key, self::phpValueToVariable($item));
            }
            $var->array($ht);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        $var->null();

        return $var;
    }

    /**
     * Resolve private key material for openssl_x509_check_private_key (stub: OpenSSLAsymmetricKey|OpenSSLCertificate|array|string).
     *
     * @return string|null PEM private key, or null when caller should return false
     */
    private static function resolveCheckPrivateKeyPem(Variable $arg): ?string
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            $lc = strtolower($object->class->name);
            if (self::KEY_LC === $lc) {
                $pem = self::keyPem($object);
                if ('' === $pem) {
                    return null;
                }
                $normalized = VmOpensslPkeyNative::normalizePrivateKeyPem($pem);
                if (false === $normalized) {
                    return null;
                }

                return $normalized;
            }
            if (self::CERT_LC === $lc) {
                // Certificates do not carry a private key; php-src returns false.
                return null;
            }

            throw new \TypeError(\sprintf(
                'openssl_x509_check_private_key(): Argument #2 ($private_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, %s given',
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                'openssl_x509_check_private_key(): Argument #2 ($private_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, %s given',
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_ARRAY === $arg->type) {
            $ht = $arg->toArray();
            $keyVar = $ht->find('0');
            if (null === $keyVar) {
                throw new \ValueError('Key array must be of the form array(0 => key, 1 => phrase)');
            }
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $keyVar->type && Variable::TYPE_OBJECT !== $keyVar->type) {
                throw new \ValueError('Key array must be of the form array(0 => key, 1 => phrase)');
            }
            $passphrase = null;
            $phraseVar = $ht->find('1');
            if (null !== $phraseVar) {
                $phraseVar = $phraseVar->resolveIndirect();
                if (Variable::TYPE_STRING === $phraseVar->type) {
                    $passphrase = $phraseVar->toString();
                }
            }
            if (Variable::TYPE_OBJECT === $keyVar->type) {
                if (self::KEY_LC !== strtolower($keyVar->toObject()->class->name)) {
                    return null;
                }
                $pem = self::keyPem($keyVar->toObject());
            } else {
                $pem = $keyVar->toString();
            }
            $normalized = VmOpensslPkeyNative::normalizePrivateKeyPem($pem, $passphrase);
            if (false === $normalized) {
                return null;
            }

            return $normalized;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                'openssl_x509_check_private_key(): Argument #2 ($private_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, %s given',
                self::typeLabel($arg)
            ));
        }

        $material = $arg->toString();
        $normalized = VmOpensslPkeyNative::normalizePrivateKeyPem($material);
        if (false === $normalized) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return string|null PEM material, or null when caller should return false
     */
    private static function resolveCertificatePem(Context $ctx, Variable $arg, string $function): ?string
    {
        unset($ctx);
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            if (self::CERT_LC === strtolower($object->class->name)) {
                return self::certificatePem($object);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                $function,
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                $function,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, %s given',
                $function,
                self::typeLabel($arg)
            ));
        }

        return $arg->toString();
    }

    /**
     * @return string|null PEM public key, or null when caller should warn + return false
     */
    private static function resolveVerifyPublicKeyPem(
        Variable $arg,
        string $function,
        int $argIndex,
        ?Frame $frame = null,
    ): ?string {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            $lc = strtolower($object->class->name);
            if (self::KEY_LC === $lc) {
                $pem = self::keyPem($object);
                if ('' === $pem) {
                    VmOpenssl::userWarningForFrame(
                        $function.'(): Don\'t know how to get public key from this private key',
                        $frame
                    );

                    return null;
                }
                $pub = VmOpensslPkeyNative::exportPublicKeyPem($pem);
                if (false === $pub) {
                    VmOpenssl::userWarningForFrame(
                        $function.'(): Don\'t know how to get public key from this private key',
                        $frame
                    );

                    return null;
                }

                return $pub;
            }
            if (self::CERT_LC === $lc) {
                $pem = self::certificatePem($object);
                if ('' === $pem) {
                    return null;
                }
                $pub = VmOpensslX509Native::extractPublicKeyPem($pem);

                return false === $pub ? null : $pub;
            }

            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|string, %s given',
                $function,
                $argIndex + 1,
                $object->class->name
            ));
        }
        if (EnumCaseSupport::isEnumCaseVariable($arg)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|string, %s given',
                $function,
                $argIndex + 1,
                EnumCaseSupport::typeNameForVariable($arg)
            ));
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|string, %s given',
                $function,
                $argIndex + 1,
                self::typeLabel($arg)
            ));
        }

        $pem = $arg->toString();
        if (str_contains($pem, 'BEGIN CERTIFICATE')) {
            $pub = VmOpensslX509Native::extractPublicKeyPem($pem);

            return false === $pub ? null : $pub;
        }

        return $pem;
    }

    private static function registerInternalClass(Context $ctx, string $lc, string $name): void
    {
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        // php-src `final class` OpenSSLCertificate / OpenSSLAsymmetricKey /
        // OpenSSLCertificateSigningRequest (ext/openssl/openssl.stub.php; #28370).
        $entry->isFinal = true;
        $ctx->classes[$lc] = $entry;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
