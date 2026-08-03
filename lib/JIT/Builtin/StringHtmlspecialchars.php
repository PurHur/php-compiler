<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__htmlspecialchars via HtmlspecialcharsJitHelper PHP (#9445, #18967, #20487).
 *
 * Embed + thin standalone AOT: {@see HtmlspecialcharsJitHelper} via {@see JitVmHelperLink}
 * (Bin2hex #20452 / HashEquals #20469 shape — no hand-written escape kernel).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::htmlspecialchars()}.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars)
 *
 * `__string__htmlspecialchars_ex` adds double_encode for encoding/arity-4 calls (#27290).
 */
final class StringHtmlspecialchars
{
    private const ABI = '__string__htmlspecialchars';

    private const ABI_EX = '__string__htmlspecialchars_ex';

    private const HELPER_PATH = '/ext/standard/HtmlspecialcharsJitHelper.php';

    private const HTMLSPECIALCHARS_HELPER = 'PHPCompiler\\ext\\standard\\HtmlspecialcharsJitHelper::htmlspecialchars';

    private const HTMLSPECIALCHARS_EX_HELPER = 'PHPCompiler\\ext\\standard\\HtmlspecialcharsJitHelper::htmlspecialcharsEx';

    private const BRIDGE_ENTRY = 'htmlspecialchars_bridge_entry';

    private const BRIDGE_ENTRY_EX = 'htmlspecialchars_ex_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HTMLSPECIALCHARS_HELPER,
        self::HTMLSPECIALCHARS_EX_HELPER,
    ];

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
        $probeEx = $context->module->getNamedFunction(self::ABI_EX);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $probeEx && $probeEx->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);
            $context->registerFunction(self::ABI_EX, $probeEx);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        self::implementBridgeEx($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::HTMLSPECIALCHARS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20487'
        );
    }

    private static function implementBridgeEx(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EX,
            self::BRIDGE_ENTRY_EX,
            [$strPtr, $i64, $i64],
            $strPtr,
            self::HTMLSPECIALCHARS_EX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27290'
        );
    }
}
