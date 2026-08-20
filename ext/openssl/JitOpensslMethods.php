<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_get_cipher_methods()/openssl_get_md_methods() (#21103),
 * openssl_get_curve_names() (#6560 VM, JIT/AOT #32364), and
 * openssl_get_cert_locations() (#6560 VM, JIT/AOT #32388).
 *
 * Cipher/md method lists are baked at compile time (peer curveNames / certLocations).
 * NestedJIT via {@see OpensslMethodsJitHelper} still references stale prelinked units
 * whose TU omits {@see OpensslCipherRegistry} const initializers (#32650, re-#30148).
 */
final class JitOpensslMethods
{
    public static function cipherMethods(Context $context, ?JITVariable $aliases = null): Value
    {
        if (null !== $aliases) {
            JitBoolArg::lowerCoerce(
                $context,
                $aliases,
                'openssl_get_cipher_methods(): Argument #1 ($aliases)'
            );
        }
        // Aliases flag is ignored in this build (OpensslCipherRegistry::cipherMethods).
        $htVar = HashTableHelper::variableFromVmHashTable($context, VmOpenssl::cipherMethods(false));

        return $htVar->value;
    }

    public static function mdMethods(Context $context, ?JITVariable $aliases = null): Value
    {
        if (null !== $aliases) {
            JitBoolArg::lowerCoerce(
                $context,
                $aliases,
                'openssl_get_md_methods(): Argument #1 ($aliases)'
            );
        }
        $htVar = HashTableHelper::variableFromVmHashTable($context, VmOpenssl::mdMethods(false));

        return $htVar->value;
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
}
