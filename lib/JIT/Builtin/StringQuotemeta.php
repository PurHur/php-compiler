<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__quotemeta via QuotemetaJitHelper + VmQuotemeta (#14705, #21589, #27011, #30858).
 *
 * NestedJIT bundle peer {@see StringChunkSplit} / #30859 and {@see StringSoundex} / #30790 —
 * solo QuotemetaJitHelper NestedJIT SIGSEGVs under thin user-script AOT (#30858 / re-#27011).
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class StringQuotemeta
{
    private const ABI = '__string__quotemeta';

    private const HELPER_PATH = '/ext/standard/QuotemetaJitHelper.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmQuotemeta.php',
        '/ext/standard/QuotemetaJitHelper.php',
    ];

    private const QUOTEMETA_HELPER = 'PHPCompiler\\ext\\standard\\QuotemetaJitHelper::quotemetaArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::QUOTEMETA_HELPER,
    ];

    private const BRIDGE_ENTRY = 'quotemeta_bridge_entry';

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
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#30858'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::QUOTEMETA_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30858'
        );
    }
}
