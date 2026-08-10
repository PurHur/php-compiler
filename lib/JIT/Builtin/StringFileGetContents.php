<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_file_get_contents via FileGetContentsJitHelper PHP (#29510, #29833).
 *
 * Always {@see JitVmHelperLink} → helper → `@file_get_contents` → NestedJIT
 * {@see \PHPCompiler\ext\standard\JitFileGetContentsLibc} (kernel Internal removed).
 * Thin libc open/read stays only behind the NestedJIT leaf (#26756) — not inlined into this ABI bridge.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::fileGetContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class StringFileGetContents
{
    private const ABI = '__compiler_file_get_contents';

    private const HELPER_PATH = '/ext/standard/FileGetContentsJitHelper.php';

    private const READ_HELPER = 'PHPCompiler\\ext\\standard\\FileGetContentsJitHelper::readPathArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::READ_HELPER,
    ];

    private const BRIDGE_ENTRY = 'fgc_bridge_entry';

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
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#29510');

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $failBb = $fn->appendBasicBlock('fgc_bridge_fail');
        $okBb = $fn->appendBasicBlock('fgc_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $isNullPath = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNullPath, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::READ_HELPER, '#29510');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
