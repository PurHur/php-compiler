<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl extension constants (php-src ext/curl/curl.stub.php; issue #6999).
 *
 * Minimal phase-0 subset; full libcurl surface lands in #3325.
 * CURLINFO_* registers with ext/curl only (#11669) — withheld until #3325.
 */
final class CurlConstants
{
    public const CURLOPT_URL = 10002;
    public const CURLOPT_RETURNTRANSFER = 19913;
    public const CURLOPT_POST = 47;
    public const CURLOPT_HTTPHEADER = 10023;
    public const CURLOPT_SHARE = 10100;
    public const CURLINFO_HTTP_CODE = 2097154;
    public const CURLINFO_EFFECTIVE_URL = 1048577;
    public const CURLE_OK = 0;
    public const CURLM_OK = 0;
    public const CURLSHOPT_NONE = 0;
    public const CURLSHOPT_SHARE = 1;
    public const CURLSHOPT_UNSHARE = 2;
    public const CURL_LOCK_DATA_COOKIE = 2;
    public const CURL_LOCK_DATA_DNS = 3;
    public const CURL_LOCK_DATA_SSL_SESSION = 4;
    public const CURL_LOCK_DATA_CONNECT = 5;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'CURLOPT_URL' => self::CURLOPT_URL,
            'CURLOPT_RETURNTRANSFER' => self::CURLOPT_RETURNTRANSFER,
            'CURLOPT_POST' => self::CURLOPT_POST,
            'CURLOPT_HTTPHEADER' => self::CURLOPT_HTTPHEADER,
            'CURLOPT_SHARE' => self::CURLOPT_SHARE,
            'CURLE_OK' => self::CURLE_OK,
            'CURLM_OK' => self::CURLM_OK,
            'CURLSHOPT_NONE' => self::CURLSHOPT_NONE,
            'CURLSHOPT_SHARE' => self::CURLSHOPT_SHARE,
            'CURLSHOPT_UNSHARE' => self::CURLSHOPT_UNSHARE,
            'CURL_LOCK_DATA_COOKIE' => self::CURL_LOCK_DATA_COOKIE,
            'CURL_LOCK_DATA_DNS' => self::CURL_LOCK_DATA_DNS,
            'CURL_LOCK_DATA_SSL_SESSION' => self::CURL_LOCK_DATA_SSL_SESSION,
            'CURL_LOCK_DATA_CONNECT' => self::CURL_LOCK_DATA_CONNECT,
        ];
    }
}
