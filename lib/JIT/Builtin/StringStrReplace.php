<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for phpc_str_replace/phpc_str_ireplace via StrReplaceJitHelper PHP (#14779, #23912).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringStrPad #23911 / #23204.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — php_str_replace
 */
final class StringStrReplace
{
    private const ABI_REPLACE = 'phpc_str_replace';

    private const ABI_IREPLACE = 'phpc_str_ireplace';

    private const ABI_TAKE_COUNT = 'phpc_str_replace_take_count';

    private const HELPER_PATH = '/ext/standard/StrReplaceJitHelper.php';

    private const REPLACE_HELPER = 'PHPCompiler\\ext\\standard\\StrReplaceJitHelper::replaceArgv';

    private const IREPLACE_HELPER = 'PHPCompiler\\ext\\standard\\StrReplaceJitHelper::ireplaceArgv';

    private const TAKE_COUNT_HELPER = 'PHPCompiler\\ext\\standard\\StrReplaceJitHelper::takeLastCount';

    private const BRIDGE_REPLACE = 'str_replace_bridge_entry';

    private const BRIDGE_IREPLACE = 'str_ireplace_bridge_entry';

    private const BRIDGE_TAKE_COUNT = 'str_replace_take_count_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REPLACE_HELPER,
        self::IREPLACE_HELPER,
        self::TAKE_COUNT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementReplace($context);
        self::implementIreplace($context);
        self::implementTakeCount($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        self::ensureLinked($context);
        $abi = $caseInsensitive ? self::ABI_IREPLACE : self::ABI_REPLACE;
        $result = $context->builder->call(
            $context->lookupFunction($abi),
            $search,
            $replace,
            $subject
        );
        if (null !== $countSlot) {
            $count = $context->builder->call($context->lookupFunction(self::ABI_TAKE_COUNT));
            $context->builder->store($count, $countSlot);
        }

        return $result;
    }

    private static function implementReplace(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_REPLACE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_REPLACE)) {
            $context->registerFunction(self::ABI_REPLACE, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REPLACE,
            self::BRIDGE_REPLACE,
            [$strPtr, $strPtr, $strPtr],
            $strPtr,
            self::REPLACE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23912'
        );
    }

    private static function implementIreplace(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_IREPLACE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_IREPLACE)) {
            $context->registerFunction(self::ABI_IREPLACE, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_IREPLACE,
            self::BRIDGE_IREPLACE,
            [$strPtr, $strPtr, $strPtr],
            $strPtr,
            self::IREPLACE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23912'
        );
    }

    private static function implementTakeCount(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_TAKE_COUNT);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_TAKE_COUNT)) {
            $context->registerFunction(self::ABI_TAKE_COUNT, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TAKE_COUNT,
            self::BRIDGE_TAKE_COUNT,
            [],
            $i64,
            self::TAKE_COUNT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23912'
        );
    }
}
