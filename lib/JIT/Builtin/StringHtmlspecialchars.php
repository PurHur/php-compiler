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
 * JIT/AOT link for __string__htmlspecialchars via HtmlspecialcharsJitHelper PHP (#9445, #18967, #20141).
 *
 * Embed / non-thin: {@see HtmlspecialcharsJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20011 shape): {@see JitHtmlspecialcharsKernel}
 * escape loop — nested helper TUs are ExternalMethod-stubbed under user-script AOT (#16075).
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
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementKernelBody($context, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
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
            '#20141'
        );
    }

    private static function implementKernelBody(Context $context, ?LlvmFunction $probe): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

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

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
