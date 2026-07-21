<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__urlencode / __string__rawurlencode via UrlencodeJitHelper PHP (#14724, #21670).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringCslashes #21617 / StringStrrev #21648 /
 * StringNl2br #21630.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/url.c — PHP_FUNCTION(urlencode), PHP_FUNCTION(rawurlencode)
 */
final class StringUrlencode
{
    private const HELPER_PATH = '/ext/standard/UrlencodeJitHelper.php';

    private const URLENCODE_ABI = '__string__urlencode';

    private const RAWURLENCODE_ABI = '__string__rawurlencode';

    private const URLENCODE_HELPER = 'PHPCompiler\\ext\\standard\\UrlencodeJitHelper::urlencodeArgv';

    private const RAWURLENCODE_HELPER = 'PHPCompiler\\ext\\standard\\UrlencodeJitHelper::rawurlencodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::URLENCODE_HELPER,
        self::RAWURLENCODE_HELPER,
    ];

    private const URLENCODE_BRIDGE_ENTRY = 'urlencode_bridge_entry';

    private const RAWURLENCODE_BRIDGE_ENTRY = 'rawurlencode_bridge_entry';

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
        self::implementUrlencode($context);
        self::implementRawurlencode($context);
    }

    private static function implementUrlencode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::URLENCODE_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::URLENCODE_BRIDGE_ENTRY)) {
            $context->registerFunction(self::URLENCODE_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::URLENCODE_ABI,
            self::URLENCODE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::URLENCODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21670'
        );
    }

    private static function implementRawurlencode(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::RAWURLENCODE_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::RAWURLENCODE_BRIDGE_ENTRY)) {
            $context->registerFunction(self::RAWURLENCODE_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::RAWURLENCODE_ABI,
            self::RAWURLENCODE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::RAWURLENCODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21670'
        );
    }
}
