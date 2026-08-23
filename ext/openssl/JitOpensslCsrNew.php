<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_csr_new() (#34061 leftover of #33527 / #6421).
 *
 * Compile-time DN assoc + private key PEM → {@see VmOpensslCsrNative::createCsrPem} → bake
 * OpenSSLCertificateSigningRequest with {@see OpensslCsrJitSupport::PROP_PEM}.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_new)
 */
final class JitOpensslCsrNew
{
    /**
     * @param JITVariable      $dn          distinguished_names array
     * @param JITVariable      $privateKey  OpenSSLAsymmetricKey|string (PEM literal for bake)
     * @param JITVariable|null $options     ?array
     */
    public static function invoke(
        Context $context,
        JITVariable $dn,
        JITVariable $privateKey,
        ?JITVariable $options = null
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_csr_new');

        $dnAssoc = self::foldCompileTimeDn($dn);
        if (null === $dnAssoc) {
            throw new \LogicException(
                'openssl_csr_new() distinguished_names must be a compile-time string-keyed array '
                .'for JIT/AOT in this compiler build (issue #34061)'
            );
        }

        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_csr_new() private_key must be a compile-time private key PEM string literal '
                .'for JIT/AOT in this compiler build (issue #34061)'
            );
        }

        $digestAlg = self::foldCompileTimeDigest($options);
        if (null === $digestAlg) {
            throw new \LogicException(
                'openssl_csr_new() options must be compile-time null/?array with foldable digest_alg '
                .'for JIT/AOT in this compiler build (issue #34061)'
            );
        }

        return self::bakeFromDnAndKeyPem($context, $dnAssoc, $keyPem, $digestAlg);
    }

    /**
     * Host FFI create CSR PEM + constant OpenSSLCertificateSigningRequest (#34061 bake path).
     *
     * @param array<string, string> $dn
     */
    public static function bakeFromDnAndKeyPem(
        Context $context,
        array $dn,
        string $privateKeyPem,
        string $digestAlg
    ): Value {
        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $normalizedKey = VmOpensslPkeyNative::normalizePrivateKeyPem($privateKeyPem, null);
        if (false === $normalizedKey || '' === $normalizedKey) {
            return self::boxedFalse($context);
        }

        $csrPem = VmOpensslCsrNative::createCsrPem($dn, $normalizedKey, $digestAlg);
        if (false === $csrPem || '' === $csrPem) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objectType = $context->type->object;
        $className = OpensslCsrJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemStr = $context->builder->load($context->constantStringFromString($csrPem));
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pemStr
        );
        $objectType->storeInstanceProperty(
            $obj,
            $className,
            OpensslCsrJitSupport::PROP_PEM,
            $pemVar
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    /**
     * @return array<string, string>|null
     */
    public static function foldCompileTimeDn(JITVariable $dn): ?array
    {
        $assoc = $dn->compileTimeAssoc ?? null;
        if (null === $assoc) {
            $arr = $dn->compileTimeArray ?? null;
            if (\is_array($arr)) {
                $assoc = $arr;
            }
        }
        if (!\is_array($assoc) || [] === $assoc) {
            return null;
        }

        $out = [];
        foreach ($assoc as $key => $value) {
            if (!\is_string($key) || '' === $key) {
                return null;
            }
            if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
                return null;
            }
            $out[$key] = (string) $value;
        }

        return $out;
    }

    /**
     * @return non-empty-string|null digest algorithm name, or null when options are not foldable
     */
    public static function foldCompileTimeDigest(?JITVariable $options): ?string
    {
        $digestAlg = 'sha256';
        if (null === $options) {
            return $digestAlg;
        }
        if (JITVariable::TYPE_NULL === $options->type || ($options->isNullConstant ?? false)) {
            return $digestAlg;
        }

        $assoc = $options->compileTimeAssoc ?? null;
        if (null === $assoc) {
            $arr = $options->compileTimeArray ?? null;
            if (\is_array($arr)) {
                $assoc = $arr;
            }
        }
        if (!\is_array($assoc)) {
            return null;
        }

        if (isset($assoc['digest_alg'])) {
            if (!\is_string($assoc['digest_alg']) || '' === $assoc['digest_alg']) {
                return null;
            }
            $digestAlg = strtolower($assoc['digest_alg']);
        }

        return $digestAlg;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
