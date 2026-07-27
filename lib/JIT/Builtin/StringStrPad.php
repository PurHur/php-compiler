<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_str_pad via StrPadJitHelper PHP (#14863, #23911).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStrRepeat #21601 / #23204.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StringStrPad
{
    private const ABI_STR_PAD = '__compiler_str_pad';

    private const HELPER_PATH = '/ext/standard/StrPadJitHelper.php';

    private const PAD_HELPER = 'PHPCompiler\\ext\\standard\\StrPadJitHelper::padArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PAD_HELPER,
    ];

    private const BRIDGE_ENTRY = 'str_pad_bridge_entry';

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

        $probe = $context->module->getNamedFunction(self::ABI_STR_PAD);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_STR_PAD, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STR_PAD,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64, $strPtr, $i64],
            $strPtr,
            self::PAD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23911'
        );
    }
}
