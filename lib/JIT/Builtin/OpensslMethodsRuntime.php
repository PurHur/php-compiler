<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for openssl_get_cipher_methods()/openssl_get_md_methods() (#21103, #30148).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (hash_algos #28750 / password_algos #9908 shape).
 * NestedJIT no longer needs a registry kernel — helper returns {@see \PHPCompiler\ext\openssl\OpensslCipherRegistry}.
 * php-src: ext/openssl/openssl.c
 */
final class OpensslMethodsRuntime
{
    private const ABI_CIPHER = '__compiler_openssl_get_cipher_methods';

    private const ABI_MD = '__compiler_openssl_get_md_methods';

    private const HELPER_PATH = '/ext/openssl/OpensslMethodsJitHelper.php';

    private const CIPHER_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslMethodsJitHelper::cipherMethodsArgv';

    private const MD_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslMethodsJitHelper::mdMethodsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CIPHER_HELPER,
        self::MD_HELPER,
    ];

    private const BRIDGE_ENTRY_CIPHER = 'ossl_cipher_methods_bridge_entry';

    private const BRIDGE_ENTRY_MD = 'ossl_md_methods_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');

        $probeCipher = $context->module->getNamedFunction(self::ABI_CIPHER);
        if (!JitVmHelperLink::hasNamedBridgeEntry($probeCipher, self::BRIDGE_ENTRY_CIPHER)) {
            JitVmHelperLink::ensureBridge(
                $context,
                self::ABI_CIPHER,
                self::BRIDGE_ENTRY_CIPHER,
                [$i64],
                $htPtr,
                self::CIPHER_HELPER,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#30148'
            );
        } else {
            $context->registerFunction(self::ABI_CIPHER, $probeCipher);
        }

        $probeMd = $context->module->getNamedFunction(self::ABI_MD);
        if (!JitVmHelperLink::hasNamedBridgeEntry($probeMd, self::BRIDGE_ENTRY_MD)) {
            JitVmHelperLink::ensureBridge(
                $context,
                self::ABI_MD,
                self::BRIDGE_ENTRY_MD,
                [$i64],
                $htPtr,
                self::MD_HELPER,
                self::HELPER_PATH,
                self::COMPILED_HELPERS,
                '#30148'
            );
        } else {
            $context->registerFunction(self::ABI_MD, $probeMd);
        }
    }
}
