<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for addslashes() (#14741, #18391, #26907, #28104).
 *
 * Embed + thin standalone AOT: NestedJIT {@see \PHPCompiler\ext\standard\AddslashesJitHelper}
 * via {@see JitVmHelperLink} (self-contained helper — no VmString NestedJIT / no thin LLVM).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::addslashes()} (VM) + helper mirrors php-src.
 * php-src: ext/standard/string.c — PHP_FUNCTION(addslashes) / php_addslashes
 */
final class StringAddslashes
{
    private const ABI = '__string__addslashes';

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
        $context->builder->clearInsertionPosition();
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
            '#28104'
        );
    }
}
