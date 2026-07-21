<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_strip_tags via StripTagsJitHelper PHP (#9196, #21711).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringVersionCompare #21706 /
 * StringUrldecode #21686 / StringCslashes #21617.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(strip_tags)
 */
final class StringStripTags
{
    private const HELPER_PATH = '/ext/standard/StripTagsJitHelper.php';

    private const ABI = '__compiler_strip_tags';

    private const STRIP_TAGS_HELPER = 'PHPCompiler\\ext\\standard\\StripTagsJitHelper::stripTags';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRIP_TAGS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'strip_tags_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $strPtr,
            self::STRIP_TAGS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21711'
        );
    }
}
