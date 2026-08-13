<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_hash_hmac_algos via HashAlgosJitHelper PHP (#18908, #19355, #20050, #20652, #28750, #30794).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (password_algos #9908 / nextafter #28716 shape).
 * NestedJIT-safe inline list in {@see \PHPCompiler\ext\hash\HashAlgosJitHelper} — not cross-dir
 * {@see \PHPCompiler\ext\standard\HashAlgosRegistry} class consts (#30794).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — php_hash_hmac_algos()
 */
final class StringHashHmacAlgos
{
    private const ABI_HASH_HMAC_ALGOS = '__compiler_hash_hmac_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const HMAC_ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::hmacAlgosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HMAC_ALGOS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'hash_hmac_algos_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_HASH_HMAC_ALGOS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_HASH_HMAC_ALGOS,
            self::BRIDGE_ENTRY,
            [],
            $htPtr,
            self::HMAC_ALGOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28750'
        );
    }
}
