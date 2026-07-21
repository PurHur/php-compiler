<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__nl2br via Nl2brJitHelper PHP (#14714, #21630).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear + {@see JitNestedHelperCoerce} i8→i64 for use_xhtml — no hand-rolled NestedJit compile loop).
 * Peer: StringStrRepeat #21601 / StringCslashes #21617 / StringQuotemeta #21589.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 */
final class StringNl2br
{
    private const ABI = '__string__nl2br';

    private const HELPER_PATH = '/ext/standard/Nl2brJitHelper.php';

    private const NL2BR_HELPER = 'PHPCompiler\\ext\\standard\\Nl2brJitHelper::nl2brArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NL2BR_HELPER,
    ];

    private const BRIDGE_ENTRY = 'nl2br_bridge_entry';

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
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i8],
            $strPtr,
            self::NL2BR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21630'
        );
    }
}
