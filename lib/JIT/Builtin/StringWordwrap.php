<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_wordwrap via WordwrapJitHelper PHP (#14565).
 *
 * Replaces ~602 LOC LLVM in JitWordwrap.php. SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
final class StringWordwrap
{
    private const ABI_WORDWRAP = '__compiler_wordwrap';

    private const HELPER_PATH = '/ext/standard/WordwrapJitHelper.php';

    private const WORDWRAP_HELPER = 'PHPCompiler\\ext\\standard\\WordwrapJitHelper::wordwrapArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WORDWRAP_HELPER,
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

        $probe = $context->module->getNamedFunction(self::ABI_WORDWRAP);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_WORDWRAP, $probe);

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
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_WORDWRAP,
            'wordwrap_bridge_entry',
            [$strPtr, $i64, $strPtr, $i8],
            $strPtr,
            self::WORDWRAP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14565'
        );
    }
}
