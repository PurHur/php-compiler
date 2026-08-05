<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __string__ucwords / __string__ucwords_ex via UcwordsJitHelper PHP (#14717, #21726, #27049).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStrrev #27007 / StringQuotemeta #27011
 * (self-contained helper — no VmString ExternalMethod stub under NestedJIT).
 * SSOT: {@see \PHPCompiler\ext\standard\UcwordsJitHelper} (mirrors {@see \PHPCompiler\ext\standard\VmString}).
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class StringUcwords
{
    private const HELPER_PATH = '/ext/standard/UcwordsJitHelper.php';

    private const UCWORDS_ABI = '__string__ucwords';

    private const UCWORDS_EX_ABI = '__string__ucwords_ex';

    private const UCWORDS_HELPER = 'PHPCompiler\\ext\\standard\\UcwordsJitHelper::ucwordsArgv';

    private const UCWORDS_EX_HELPER = 'PHPCompiler\\ext\\standard\\UcwordsJitHelper::ucwordsExArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::UCWORDS_HELPER,
        self::UCWORDS_EX_HELPER,
    ];

    private const UCWORDS_BRIDGE_ENTRY = 'ucwords_bridge_entry';

    private const UCWORDS_EX_BRIDGE_ENTRY = 'ucwords_ex_bridge_entry';

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
        self::implementUcwords($context);
        self::implementUcwordsEx($context);
    }

    private static function implementUcwords(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::UCWORDS_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::UCWORDS_BRIDGE_ENTRY)) {
            $context->registerFunction(self::UCWORDS_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::UCWORDS_ABI,
            self::UCWORDS_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::UCWORDS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21726'
        );
    }

    private static function implementUcwordsEx(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::UCWORDS_EX_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::UCWORDS_EX_BRIDGE_ENTRY)) {
            $context->registerFunction(self::UCWORDS_EX_ABI, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::UCWORDS_EX_ABI,
            self::UCWORDS_EX_BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $strPtr,
            self::UCWORDS_EX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21726'
        );
    }
}
