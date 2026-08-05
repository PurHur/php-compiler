<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__quotemeta via QuotemetaJitHelper PHP (#14705, #21589, #27011).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStrRot13 #26868 / StringStrrev #27007
 * (self-contained helper — no VmString ExternalMethod stub under NestedJIT).
 * SSOT: {@see \PHPCompiler\ext\standard\QuotemetaJitHelper} (mirrors {@see \PHPCompiler\ext\standard\VmString}).
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class StringQuotemeta
{
    private const ABI = '__string__quotemeta';

    private const HELPER_PATH = '/ext/standard/QuotemetaJitHelper.php';

    private const QUOTEMETA_HELPER = 'PHPCompiler\\ext\\standard\\QuotemetaJitHelper::quotemetaArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::QUOTEMETA_HELPER,
    ];

    private const BRIDGE_ENTRY = 'quotemeta_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implement(Context $context): void
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::QUOTEMETA_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21589'
        );
    }
}
