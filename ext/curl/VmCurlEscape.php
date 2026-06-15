<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\VmString;

/**
 * curl_escape() / curl_unescape() — RFC 3986 percent encoding (php-src ext/curl/interface.c).
 *
 * curl_easy_escape/unescape use the same unreserved set as rawurlencode/rawurldecode.
 */
final class VmCurlEscape
{
    public static function escape(string $value): string
    {
        return VmString::rawurlencode($value);
    }

    public static function unescape(string $value): string
    {
        return VmString::rawurldecode($value);
    }
}
