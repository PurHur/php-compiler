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
 * Replaces ~150-line LLVM magic-byte sniff + libc strncmp. SSOT: {@see \PHPCompiler\ext\standard\VmMime}.
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GetcwdJit #25541).
 * Save/restore insert block on first-use link so thin AOT call sites are not left parentless
 * (peer StringReadfile / StringFileGetContents; STANDALONE Type::initialize returns before
 * ensureLinked — #12910).
 * data:// NestedJIT pulls base64_decode from decodeDataUri (#34789 / peer #34731).
 */
final class MimeContentTypeRuntime
{
    private const ABI = '__compiler_mime_content_type';

    private const HELPER_PATH = '/ext/standard/MimeContentTypeJitHelper.php';

    private const MIME_HELPER = 'PHPCompiler\\ext\\standard\\MimeContentTypeJitHelper::mimeContentType';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MIME_HELPER,
    ];

    private const BRIDGE_ENTRY = 'mime_content_type_bridge_entry';

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

        // data:// NestedJIT pulls base64_decode from decodeDataUri (#34789 / peer #34731).
        StringBase64Decode::ensureLinked($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        // Declare ABI module-locally when Type no longer always-on (#33034).
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );
        $context->registerFunction(self::ABI, $fn);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$fn->getParam(0)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
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
            '#25544'
        );
    }
}
