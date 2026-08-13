<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for wordwrap() via WordwrapJitHelper + VmWordwrap (#14565, #30812).
 *
 * NestedJIT bundle peer {@see StringSoundex} / #30790 — solo WordwrapJitHelper NestedJIT
 * SIGSEGVs under thin user-script AOT (`$s[$i]` / isset loops).
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
final class StringWordwrap
{
    private const ABI_WORDWRAP = '__compiler_wordwrap';

    private const HELPER_PATH = '/ext/standard/WordwrapJitHelper.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmWordwrap.php',
        '/ext/standard/WordwrapJitHelper.php',
    ];

    private const WORDWRAP_HELPER = 'PHPCompiler\\ext\\standard\\WordwrapJitHelper::wordwrapArgv';

    private const BRIDGE_ENTRY = 'wordwrap_bridge_entry';

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

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_WORDWRAP);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
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
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#30812'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_WORDWRAP,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64, $strPtr, $i8],
            $strPtr,
            self::WORDWRAP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30812'
        );
    }
}
