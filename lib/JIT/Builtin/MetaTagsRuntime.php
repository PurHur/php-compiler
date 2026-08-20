<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_get_meta_tags via MetaTagsJitHelper PHP (#9338, #26568, #33035).
 *
 * Owns the ABI module-locally via {@see JitVmHelperLink::ensureBridge} (getNamedFunction first,
 * then addFunction if absent). Do not re-add empty always-on shells in {@see Type} — leftover
 * decls mint get_meta_tags.1 (#31894 / #32122).
 *
 * Thin standalone AOT prefers compile-time {@see \PHPCompiler\ext\standard\VmMetaTags} folds in
 * {@see \PHPCompiler\ext\standard\get_meta_tags::call} when the path is a literal (#33035).
 *
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsRuntime
{
    private const ABI_NAME = '__compiler_get_meta_tags';

    private const HELPER_PATH = '/ext/standard/MetaTagsJitHelper.php';

    private const GET_META_TAGS_HELPER = 'PHPCompiler\\ext\\standard\\MetaTagsJitHelper::getMetaTags';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_META_TAGS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'meta_tags_bridge_entry';

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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            self::BRIDGE_ENTRY,
            [$strPtr, $i1],
            $htPtr,
            self::GET_META_TAGS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#33035'
        );
    }
}
