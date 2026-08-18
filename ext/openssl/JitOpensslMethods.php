<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Builtin\OpensslMethodsCrypto;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_get_cipher_methods()/openssl_get_md_methods() (#21103),
 * openssl_get_curve_names() (#6560 VM, JIT/AOT #32364), and
 * openssl_get_cert_locations() (#6560 VM, JIT/AOT #32388).
 */
final class JitOpensslMethods
{
    public static function cipherMethods(Context $context, ?JITVariable $aliases = null): Value
    {
        return self::invoke($context, '__compiler_openssl_get_cipher_methods', 'openssl_get_cipher_methods', $aliases);
    }

    public static function mdMethods(Context $context, ?JITVariable $aliases = null): Value
    {
        return self::invoke($context, '__compiler_openssl_get_md_methods', 'openssl_get_md_methods', $aliases);
    }

    /**
     * openssl_get_curve_names() — bake {@see VmOpenssl::curveNames()} like curl_version (#24463).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_get_curve_names) / EC_get_builtin_curves
     */
    public static function curveNames(Context $context): Value
    {
        $htVar = HashTableHelper::variableFromVmHashTable($context, VmOpenssl::curveNames());

        return $htVar->value;
    }

    /**
     * openssl_get_cert_locations() — bake {@see VmOpenssl::certLocations()} like curve names (#32364).
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_get_cert_locations)
     * / X509_get_default_cert_file / openssl.cafile / openssl.capath
     */
    public static function certLocations(Context $context): Value
    {
        $htVar = HashTableHelper::variableFromVmHashTable($context, VmOpenssl::certLocations());

        return $htVar->value;
    }

    private static function invoke(
        Context $context,
        string $abi,
        string $function,
        ?JITVariable $aliases
    ): Value {
        OpensslMethodsCrypto::ensureLinked($context);

        $aliasesI64 = null === $aliases
            ? $context->getTypeFromString('int64')->constInt(0, false)
            : $context->builder->zExt(
                JitBoolArg::lowerCoerce(
                    $context,
                    $aliases,
                    sprintf('%s(): Argument #1 ($aliases)', $function)
                ),
                $context->getTypeFromString('int64')
            );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $raw = $context->builder->call($context->lookupFunction($abi), $aliasesI64);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $raw
        );

        return $ptr;
    }
}
