<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_strrev via StrrevJitHelper PHP (#14566, #21648, #27007).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStrRot13 #26868 / Bin2hex #20452
 * (self-contained helper — no VmString ExternalMethod stub under NestedJIT).
 * SSOT: {@see \PHPCompiler\ext\standard\StrrevJitHelper} (mirrors {@see \PHPCompiler\ext\standard\VmString}).
 * php-src: ext/standard/string.c — PHP_FUNCTION(strrev)
 */
final class StringStrrev
{
    private const ABI = '__compiler_strrev';

    private const HELPER_PATH = '/ext/standard/StrrevJitHelper.php';

    private const STRREV_HELPER = 'PHPCompiler\\ext\\standard\\StrrevJitHelper::strrevArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRREV_HELPER,
    ];

    private const BRIDGE_ENTRY = 'strrev_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::STRREV_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21648'
        );
    }
}
