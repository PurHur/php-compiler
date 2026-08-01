<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * get_headers() for compiled JIT/AOT modules (#9212, php-in-PHP).
 *
 * SSOT: {@see get_headers::execute()} via VmHttpFetchNative / VmHttpHeaders.
 * php-src: ext/standard/head.c — PHP_FUNCTION(get_headers)
 */
final class GetHeadersJitHelper
{
    public static function getHeaders(string $url, bool $associative): ?HashTable
    {
        if (!VmHttpLastResponseHeaders::isHttpUrl($url)) {
            // php-src head.c — same Warning as VM {@see get_headers::execute} (#26383).
            TriggerErrorJitHelper::warning(get_headers::NON_HTTP_URL_WARNING);

            return null;
        }

        $headers = VmHttpFetchNative::fetchHeaders($url);
        if (false === $headers) {
            return null;
        }

        $formatted = VmHttpHeaders::format($headers, $associative);

        return VmHttpHeaders::toHashTable($formatted, $associative);
    }
}
