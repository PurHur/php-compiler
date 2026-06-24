<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * get_meta_tags() for compiled JIT/AOT modules (#9338, php-in-PHP).
 *
 * SSOT: {@see VmMetaTags::getMetaTagsHashTable()}
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsJitHelper
{
    public static function getMetaTags(string $filename, bool $useIncludePath): ?HashTable
    {
        return VmMetaTags::getMetaTagsHashTable($filename, $useIncludePath);
    }
}
