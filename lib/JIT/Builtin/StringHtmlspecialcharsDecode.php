<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__htmlspecialchars_decode via HtmlspecialcharsDecodeJitHelper PHP (#14820, #18954).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889) instead of LLVM defer.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::htmlspecialchars_decode()}.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars_decode)
 */
final class StringHtmlspecialcharsDecode
{
    private const ABI = '__string__htmlspecialchars_decode';

    private const HELPER_PATH = '/ext/standard/HtmlspecialcharsDecodeJitHelper.php';

    private const HTMLSPECIALCHARS_DECODE_HELPER = 'PHPCompiler\\ext\\standard\\HtmlspecialcharsDecodeJitHelper::htmlspecialcharsDecodeArgv';

    private const BRIDGE_ENTRY = 'htmlspecialchars_decode_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HTMLSPECIALCHARS_DECODE_HELPER,
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

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
            self::HTMLSPECIALCHARS_DECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18954'
        );
    }
}
