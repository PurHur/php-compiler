<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_hash_algos via HashAlgosJitHelper PHP (#14909, #19355, #20050, #20652, #28750).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (password_algos #9908 / nextafter #28716 shape).
 * NestedJIT no longer needs a registry kernel — helper returns {@see \PHPCompiler\ext\standard\HashAlgosRegistry}.
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHash::algos()}
 * php-src: ext/hash/hash.c — php_hash_algos()
 */
final class StringHashAlgos
{
    private const ABI_HASH_ALGOS = '__compiler_hash_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::algosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ALGOS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'hash_algos_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_HASH_ALGOS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_HASH_ALGOS, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_HASH_ALGOS,
            self::BRIDGE_ENTRY,
            [],
            $htPtr,
            self::ALGOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28750'
        );
    }
}
