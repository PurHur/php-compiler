<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__preg_quote via PregQuoteJitHelper PHP (#14743, #21751).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStripTags #21711 / StringUcwords #21726.
 * User-script AOT: helper logical is USER_SCRIPT_INLINE_ONLY (#27564) so NestedJIT runs instead of
 * the prelinked unit.o that returns "" on cache hit.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(preg_quote)
 */
final class StringPregQuote
{
    private const HELPER_PATH = '/ext/standard/PregQuoteJitHelper.php';

    private const ABI = '__string__preg_quote';

    private const PREG_QUOTE_HELPER = 'PHPCompiler\\ext\\standard\\PregQuoteJitHelper::pregQuoteArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PREG_QUOTE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'preg_quote_bridge_entry';

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
            self::PREG_QUOTE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21751'
        );
    }
}
