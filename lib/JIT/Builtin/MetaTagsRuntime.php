<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_get_meta_tags via MetaTagsJitHelper PHP (#9338, #26568, #33051, #34787).
 *
 * Owns the ABI module-locally: {@see getNamedFunction} first, then {@see addFunction}
 * if absent. Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * get_meta_tags.1 (#31894 / #32122).
 * Thin standalone AOT links via {@see ensureStandaloneBodies} (peer StringFileGetContents
 * #33030 / #13571) — Type::initialize returns early for STANDALONE/EMBED.
 * Bridge reads via {@see __compiler_file_get_contents} (data:// NestedJIT-safe — #34731)
 * then NestedJIT {@see MetaTagsJitHelper::parseHtmlToNativeHt} (#34787).
 * Helper returns native HT i64 (not NestedJIT HashTable — #27551 / #26942); bridge converts
 * via {@see JitNestedHelperCoerce::i64ToTypedPtr}.
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: parentless call / module verify — peer GetHeadersRuntime #27317 / #27088).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GcCollectCyclesCollectRuntime #26532).
 * SSOT {@see \PHPCompiler\ext\standard\VmMetaTags}.
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsRuntime
{
    private const ABI_NAME = '__compiler_get_meta_tags';

    private const HELPER_PATH = '/ext/standard/MetaTagsJitHelper.php';

    private const PARSE_HTML_HELPER = 'PHPCompiler\\ext\\standard\\MetaTagsJitHelper::parseHtmlToNativeHt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_HTML_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27317 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        // data:// via __compiler_file_get_contents (#34787 / peer #34731).
        StringBase64Decode::ensureLinked($context);
        StringFileGetContents::ensureLinked($context);
        IncludePathRuntime::ensureLinked($context);
        self::ensureNativeHtInternalProxies($context);
        self::ensureJitHelperCompiled($context);
        self::implementGetMetaTagsBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementGetMetaTagsBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('meta_tags_bridge_entry');
        $resolveBb = $fn->appendBasicBlock('meta_tags_bridge_resolve_inc');
        $readBb = $fn->appendBasicBlock('meta_tags_bridge_read');
        $failBb = $fn->appendBasicBlock('meta_tags_bridge_fail');
        $parseBb = $fn->appendBasicBlock('meta_tags_bridge_parse');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $useInc = $fn->getParam(1);
        $nullStr = $strPtr->constNull();
        $context->builder->branchIf($useInc, $resolveBb, $readBb);

        $context->builder->positionAtEnd($resolveBb);
        $resolved = $context->builder->call(
            $context->lookupFunction('__compiler_stream_resolve_include_path'),
            $path
        );
        $hasResolved = $context->builder->icmp(Builder::INT_NE, $resolved, $nullStr);
        $useResolvedBb = $fn->appendBasicBlock('meta_tags_bridge_use_resolved');
        $context->builder->branchIf($hasResolved, $useResolvedBb, $readBb);

        $context->builder->positionAtEnd($useResolvedBb);
        $context->builder->branch($readBb);

        $context->builder->positionAtEnd($readBb);
        $pathPhi = $context->builder->phi($strPtr, 'meta_tags_path');
        $pathPhi->addIncoming($path, $entry);
        $pathPhi->addIncoming($path, $resolveBb);
        $pathPhi->addIncoming($resolved, $useResolvedBb);

        // Always __compiler_file_get_contents — data:// NestedJIT-safe (#34787 / peer #34731).
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathPhi
        );
        $readFailed = $context->builder->icmp(Builder::INT_EQ, $contents, $nullStr);
        $context->builder->branchIf($readFailed, $failBb, $parseBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($parseBb);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::PARSE_HTML_HELPER),
            [$contents]
        );
        // Native HT helpers return i64 ptr (0 = false) — peer parse_str / getenv (#13827).
        $i64 = $context->getTypeFromString('int64');
        $asI64 = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        $ht = JitNestedHelperCoerce::i64ToTypedPtr($context, $asI64, $htPtr);
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26568');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26568'
        );
    }

    /** Register phpc_native_ht_* Internal JIT handlers before NestedJIT (#13827 / #13900). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after MetaTagsRuntime bridge (#9338)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
