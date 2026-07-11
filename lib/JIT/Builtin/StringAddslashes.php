<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;

/**
 * JIT/AOT link for addslashes() — LLVM on __string__addslashes or AddslashesJitHelper PHP (#16734).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(addslashes)
 */
final class StringAddslashes
{
    private const ABI = 'phpc_jit_addslashes';

    private const HELPER_PATH = '/ext/standard/AddslashesJitHelper.php';

    private const ADDSLASHES_HELPER = 'PHPCompiler\\ext\\standard\\AddslashesJitHelper::addslashesArgv';

    private const BRIDGE_ENTRY = 'addslashes_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADDSLASHES_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringAddslashesLlvm::implement($context);

            return;
        }

        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::ADDSLASHES_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#14741'
        );
    }
}
