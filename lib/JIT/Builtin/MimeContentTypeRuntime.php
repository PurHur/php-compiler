<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_mime_content_type via MimeContentTypeJitHelper PHP (#9236, #25544).
 *
 * Replaces ~150-line LLVM magic-byte sniff + libc strncmp. SSOT: {@see \PHPCompiler\ext\standard\VmMime}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GetcwdJit #25541).
 */
final class MimeContentTypeRuntime
{
    private const HELPER_PATH = '/ext/standard/MimeContentTypeJitHelper.php';

    private const MIME_HELPER = 'PHPCompiler\\ext\\standard\\MimeContentTypeJitHelper::mimeContentType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MIME_HELPER,
    ];

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
        $probe = $context->module->getNamedFunction('__compiler_mime_content_type');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_mime_content_type', $probe);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction('__compiler_mime_content_type');

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('mime_content_type_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$fn->getParam(0)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_mime_content_type', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::MIME_HELPER, '#25544');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25544'
        );
    }
}
