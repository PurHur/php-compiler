<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_version_compare via VersionCompareJitHelper PHP (#9813, #21706).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringUrldecode #21686 / StringUrlencode #21670 /
 * StringNl2br #21630.
 * SSOT: {@see \PHPCompiler\ext\standard\VmInfo}.
 * php-src: ext/standard/versioning.c — php_version_compare
 */
final class StringVersionCompare
{
    private const ABI = '__compiler_version_compare';

    private const HELPER_PATH = '/ext/standard/VersionCompareJitHelper.php';

    private const COMPARE_HELPER = 'PHPCompiler\\ext\\standard\\VersionCompareJitHelper::compare';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPARE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'version_compare_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $i64,
            self::COMPARE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21706'
        );
    }
}
