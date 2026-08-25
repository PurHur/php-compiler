<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_get_meta_tags via MetaTagsJitHelper PHP (#9338, #26568, #33051).
 *
 * Owns the ABI module-locally: {@see getNamedFunction} first, then {@see addFunction}
 * if absent. Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * get_meta_tags.1 (#31894 / #32122).
 * Thin standalone AOT links via {@see ensureStandaloneBodies} (peer StringFileGetContents
 * #33030 / #13571) — Type::initialize returns early for STANDALONE/EMBED.
 * Helper returns native HT i64 (not NestedJIT HashTable — #27551 / #26942); bridge converts
 * via {@see JitNestedHelperCoerce::i64ToTypedPtr}.
 * Call-site {@see ensureLinked} restores the caller insert block after bridge emit
 * (thin AOT: parentless call / module verify — peer GetHeadersRuntime #27317 / #27088).
 * Helper compile: {@see JitVmHelperLink::ensureCompiledBundle} with FileGetContentsJitHelper
 * for data:// NestedJIT decode (peer StringReadfile #34731 / #34787).
 * SSOT {@see \PHPCompiler\ext\standard\VmMetaTags}.
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsRuntime
{
    private const ABI_NAME = '__compiler_get_meta_tags';

    private const HELPER_PATH = '/ext/standard/MetaTagsJitHelper.php';

    private const FGC_HELPER_PATH = '/ext/standard/FileGetContentsJitHelper.php';

    private const GET_META_TAGS_HELPER = 'PHPCompiler\\ext\\standard\\MetaTagsJitHelper::getMetaTags';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_META_TAGS_HELPER,
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
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GET_META_TAGS_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
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
        // data:// via FileGetContentsJitHelper::readPathArgv NestedJIT (#34787 / peer #34731).
        StringBase64Decode::ensureLinked($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            [
                self::FGC_HELPER_PATH,
                self::HELPER_PATH,
            ],
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
