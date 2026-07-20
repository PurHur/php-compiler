<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_str_repeat via StrRepeatJitHelper PHP (#14602, #21601).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringChunkSplit #21399 / StringQuotemeta #21589.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_repeat)
 */
final class StringStrRepeat
{
    private const ABI = '__compiler_str_repeat';

    private const HELPER_PATH = '/ext/standard/StrRepeatJitHelper.php';

    private const STR_REPEAT_HELPER = 'PHPCompiler\\ext\\standard\\StrRepeatJitHelper::strRepeatArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STR_REPEAT_HELPER,
    ];

    private const BRIDGE_ENTRY = 'str_repeat_bridge_entry';

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
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::STR_REPEAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21601'
        );
    }
}
