<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_x509_parse() (#32496 leftover of #6274).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_parse)
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslX509Native} cannot
 * call `$ffi->X509_free()` (peer JitOpensslError / #32336). Bake the parse result in the
 * compiler process (which does have libcrypto FFI), like {@see JitOpensslMethods::certLocations()}.
 *
 * PEM and $short_names must be compile-time literals. OpenSSLCertificate objects stay VM-only.
 */
final class JitOpensslX509
{
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

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
