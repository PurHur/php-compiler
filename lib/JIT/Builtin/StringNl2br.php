<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__nl2br via Nl2brJitHelper + VmNl2br (#14714, #21630, #30813).
 *
 * NestedJIT bundle peer {@see StringWordwrap} / #30812 — solo Nl2brJitHelper NestedJIT
 * SIGSEGVs under thin user-script AOT (`$s[$i]` loops in former VmString::nl2br).
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 */
final class StringNl2br
{
    private const ABI = '__string__nl2br';

    private const HELPER_PATH = '/ext/standard/Nl2brJitHelper.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmNl2br.php',
        '/ext/standard/Nl2brJitHelper.php',
    ];

    private const NL2BR_HELPER = 'PHPCompiler\\ext\\standard\\Nl2brJitHelper::nl2brArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NL2BR_HELPER,
    ];

    private const BRIDGE_ENTRY = 'nl2br_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
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
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#30813'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i8],
            $strPtr,
            self::NL2BR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30813'
        );
    }
}
