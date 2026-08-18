<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl_share_strerror() for compiled JIT/AOT (#32340, php-in-PHP).
 *
 * NestedJIT-safe: CURLSHE_* strings as a class const (posix_strerror #12477 /
 * openssl_get_*_methods #30148 shape) — no libcurl FFI in the AOT binary.
 *
 * SSOT for VM: {@see VmCurlCore::shareStrerror()}
 * php-src: ext/curl/share.c — PHP_FUNCTION(curl_share_strerror)
 */
final class CurlShareStrerrorJitHelper
{
    /** @var array<int, string> php-src curl_share_strerror / CURLSHE_* (#20531) */
    public const SHARE_ERRORS = [
        0 => 'No error',
        1 => 'Unknown share option',
        2 => 'Share currently in use',
        3 => 'Invalid share handle',
        4 => 'Out of memory',
        5 => 'Feature not enabled in this library',
    ];

    public static function message(int $code): string
    {
        return self::SHARE_ERRORS[$code] ?? 'CURLSHcode unknown';
    }
}
