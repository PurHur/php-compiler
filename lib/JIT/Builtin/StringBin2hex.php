<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for bin2hex() via Bin2hexJitHelper PHP (#14603, #18884, #19126).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class StringBin2hex
{
    private const ABI = '__compiler_bin2hex';

    private const HELPER_PATH = '/ext/standard/Bin2hexJitHelper.php';

    private const BIN2HEX_HELPER = 'PHPCompiler\\ext\\standard\\Bin2hexJitHelper::bin2hexArgv';

    private const BRIDGE_ENTRY = 'bin2hex_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BIN2HEX_HELPER,
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
            self::BIN2HEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19126'
        );
    }
}
