<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_file_get_contents via FileGetContentsJitHelper PHP (#15309).
 *
 * Replaces ~207 LOC inline libc open/read LLVM.
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

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#15309');

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);

        $entry = $fn->appendBasicBlock('fgc_bridge_entry');
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
        $context->builder->clearInsertionPosition();
    }
}
