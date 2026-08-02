<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_str_rot13 via StrRot13JitHelper PHP (#14896, #26868).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889). Peer: StringBin2hex #20452.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::strRot13()} (VM); helper is NestedJIT-self-contained.
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_rot13)
 */
final class StringStrRot13
{
    private const ABI_STR_ROT13 = '__compiler_str_rot13';

    private const HELPER_PATH = '/ext/standard/StrRot13JitHelper.php';

    private const ROT13_HELPER = 'PHPCompiler\\ext\\standard\\StrRot13JitHelper::rot13Argv';

    private const BRIDGE_ENTRY = 'str_rot13_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ROT13_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_STR_ROT13);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_STR_ROT13, $probe);

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
            self::ABI_STR_ROT13,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::ROT13_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26868'
        );
    }
}
