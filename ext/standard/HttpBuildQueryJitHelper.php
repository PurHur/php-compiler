<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * http_build_query() for compiled JIT/AOT modules (#9443, php-in-PHP).
 *
 * SSOT: {@see VmHttpBuildQuery::buildFromHashTable()}
 * php-src: ext/standard/http.c — php_url_encode_hash_ex, http_build_query
 */
final class HttpBuildQueryJitHelper
{
    public static function build(
        HashTable $data,
        string $prefix,
        string $separator,
        int $encoding
    ): string {
        return VmHttpBuildQuery::buildFromHashTable($data, $prefix, $separator, $encoding);
    }
}
