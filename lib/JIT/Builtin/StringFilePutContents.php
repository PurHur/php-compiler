<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_file_put_contents via FilePutContentsJitHelper PHP (#15310, #19966, #30127).
 *
 * Always {@see JitVmHelperLink} → helper → `@file_put_contents` NestedJIT leaf
 * (libc fopen/fwrite leaf via file_put_contents::call; no kernel Internal).
 * Pre-registerModule NestedJIT resolves builtins via Runtime modules (#15417 / #29833).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::filePutContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class StringFilePutContents
{
    private const ABI = '__compiler_file_put_contents';

    private const HELPER_PATH = '/ext/standard/FilePutContentsJitHelper.php';

    private const WRITE_HELPER = 'PHPCompiler\\ext\\standard\\FilePutContentsJitHelper::writePathArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WRITE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'fpc_bridge_entry';

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

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#19966');

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $failBb = $fn->appendBasicBlock('fpc_bridge_fail');
        $okBb = $fn->appendBasicBlock('fpc_bridge_ok');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $data = $fn->getParam(1);
        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull())
        );
        $context->builder->branchIf($badArgs, $failBb, $okBb);

        $context->builder->positionAtEnd($okBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::WRITE_HELPER, '#19966');
        $flags = $fn->getParam(2);
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path, $data, $flags]);
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64)
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i64->constInt(-1, false));

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
