<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__urldecode / __string__rawurldecode via UrldecodeJitHelper PHP (#14726, #21686).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringUrlencode #21670 / StringStrrev #21648 /
 * StringNl2br #21630.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/url.c — PHP_FUNCTION(urldecode), PHP_FUNCTION(rawurldecode)
 */
final class StringUrldecode
{
    private const HELPER_PATH = '/ext/standard/UrldecodeJitHelper.php';

    private const URLDECODE_ABI = '__string__urldecode';

    private const RAWURLDECODE_ABI = '__string__rawurldecode';

    private const URLDECODE_HELPER = 'PHPCompiler\\ext\\standard\\UrldecodeJitHelper::urldecodeArgv';

    private const RAWURLDECODE_HELPER = 'PHPCompiler\\ext\\standard\\UrldecodeJitHelper::rawurldecodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::URLDECODE_HELPER,
        self::RAWURLDECODE_HELPER,
    ];

    private const URLDECODE_BRIDGE_ENTRY = 'urldecode_bridge_entry';

    private const RAWURLDECODE_BRIDGE_ENTRY = 'rawurldecode_bridge_entry';

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
        self::implementUrldecode($context);
        self::implementRawurldecode($context);
    }

    private static function implementUrldecode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::URLDECODE_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::URLDECODE_BRIDGE_ENTRY)) {
            $context->registerFunction(self::URLDECODE_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::URLDECODE_ABI,
            self::URLDECODE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::URLDECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21686'
        );
    }

    private static function implementRawurldecode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::RAWURLDECODE_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::RAWURLDECODE_BRIDGE_ENTRY)) {
            $context->registerFunction(self::RAWURLDECODE_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::RAWURLDECODE_ABI,
            self::RAWURLDECODE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::RAWURLDECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21686'
        );
    }
}
