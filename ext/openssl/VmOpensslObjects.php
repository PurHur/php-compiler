<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
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

    public static function keyPem(\PHPCompiler\VM\ObjectEntry $entry): string
    {
        return self::$keyStore[$entry->id] ?? '';
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

    private static function registerInternalClass(Context $ctx, string $lc, string $name): void
    {
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        $ctx->classes[$lc] = $entry;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
