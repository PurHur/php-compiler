<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_mime_content_type via MimeContentTypeJitHelper PHP (#9236, #25544, #33034).
 *
 * Owns the ABI module-locally: {@see getNamedFunction} first, then {@see addFunction}
 * if absent. Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * mime_content_type.1 (#31894 / #32122).
 * Skip body emit under NestedJIT (peer StringFileGetContents / #26900 early-init).
 * SSOT: {@see \PHPCompiler\ext\standard\VmMime}. php-src: ext/standard/file.c.
 */
final class MimeContentTypeRuntime
{
    private const ABI = '__compiler_mime_content_type';

    private const HELPER_PATH = '/ext/standard/MimeContentTypeJitHelper.php';

    private const MIME_HELPER = 'PHPCompiler\\ext\\standard\\MimeContentTypeJitHelper::mimeContentType';

    private const BRIDGE_ENTRY = 'mime_content_type_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::MIME_HELPER];

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

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );
        $context->registerFunction(self::ABI, $fn);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
            '#25544',
            true
        );
    }
}
