<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for addcslashes/stripcslashes via CslashesJitHelper PHP (#5652, #9578, #21617).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringAddslashes #18391 / StringQuotemeta #21589 /
 * StringStrRepeat #21601.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(addcslashes) / PHP_FUNCTION(stripcslashes)
 */
final class StringCslashes
{
    private const HELPER_PATH = '/ext/standard/CslashesJitHelper.php';

    private const ADDCSLASHES_ABI = '__compiler_addcslashes';

    private const STRIPCSLASHES_ABI = '__compiler_stripcslashes';

    private const ADDCslashes_HELPER = 'PHPCompiler\\ext\\standard\\CslashesJitHelper::addcslashes';

    private const STRIPCslashes_HELPER = 'PHPCompiler\\ext\\standard\\CslashesJitHelper::stripcslashes';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADDCslashes_HELPER,
        self::STRIPCslashes_HELPER,
    ];

    private const ADDCSLASHES_BRIDGE_ENTRY = 'addcslashes_bridge_entry';

    private const STRIPCSLASHES_BRIDGE_ENTRY = 'stripcslashes_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStripcslashes(Context $context): void
    {
        self::implementStripcslashes($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
        self::ensureStripcslashes($context);
    }

    public static function implement(Context $context): void
    {
        self::implementAddcslashes($context);
    }

    public static function implementStripcslashes(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::STRIPCSLASHES_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::STRIPCSLASHES_BRIDGE_ENTRY)) {
            $context->registerFunction(self::STRIPCSLASHES_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::STRIPCSLASHES_ABI,
            self::STRIPCSLASHES_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::STRIPCslashes_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21617'
        );
    }

    private static function implementAddcslashes(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ADDCSLASHES_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::ADDCSLASHES_BRIDGE_ENTRY)) {
            $context->registerFunction(self::ADDCSLASHES_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ADDCSLASHES_ABI,
            self::ADDCSLASHES_BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $strPtr,
            self::ADDCslashes_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21617'
        );
    }
}
