<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitHtmlspecialcharsKernel;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__htmlspecialchars via HtmlspecialcharsJitHelper PHP (#9445, #18967, #19389).
 *
 * Embed / non-deferred: {@see HtmlspecialcharsJitHelper} via {@see JitVmHelperLink}.
 * User-script / heavy StreamIo defer: thin {@see JitHtmlspecialcharsKernel} identity stub —
 * nested helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075, #18974).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::htmlspecialchars()}.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars)
 */
final class StringHtmlspecialchars
{
    private const ABI = '__string__htmlspecialchars';

    private const HELPER_PATH = '/ext/standard/HtmlspecialcharsJitHelper.php';

    private const HTMLSPECIALCHARS_HELPER = 'PHPCompiler\\ext\\standard\\HtmlspecialcharsJitHelper::htmlspecialchars';

    private const BRIDGE_ENTRY = 'htmlspecialchars_bridge_entry';

    private const KERNEL_ENTRY = 'htmlspecialchars_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HTMLSPECIALCHARS_HELPER,
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
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        if (null !== $probe && JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        if (StreamIoRuntime::shouldDeferHeavyStreamIoEmitters($context)) {
            self::implementUserScriptKernel($context, $probe);
        } else {
            self::implementBridge($context);
        }
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
            '#18967'
        );
    }

    private static function implementUserScriptKernel(Context $context, ?LlvmFunction $probe): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr, $i64)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitHtmlspecialcharsKernel::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);
    }
}
