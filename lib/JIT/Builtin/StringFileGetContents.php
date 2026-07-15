<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\ext\standard\JitFileGetContentsKernel;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_file_get_contents via FileGetContentsJitHelper PHP (#15309, #19279).
 *
 * Embed / non-user-script: {@see FileGetContentsJitHelper} via {@see JitVmHelperLink}.
 * User-script standalone AOT: thin {@see JitFileGetContentsKernel} libc open/read — nested
 * helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075).
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

    private const KERNEL_ENTRY = 'fgc_kernel_entry';

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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementUserScriptKernel($context);

            return;
        }

        self::implementPhpBridge($context, $probe);
    }

    private static function implementPhpBridge(Context $context, ?\PHPLLVM\Value\Function_ $probe): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15309');

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
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::READ_HELPER, '#15309');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$path]);
        $isNullResult = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $failResultBb = $fn->appendBasicBlock('fgc_bridge_result_fail');
        $okResultBb = $fn->appendBasicBlock('fgc_bridge_result_ok');
        $context->builder->branchIf($isNullResult, $failResultBb, $okResultBb);

        $context->builder->positionAtEnd($failResultBb);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($okResultBb);
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->returnValue($result);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementUserScriptKernel(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitFileGetContentsKernel::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
