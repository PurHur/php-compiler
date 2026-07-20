<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Pure-PHP curl introspection helpers (php-src ext/curl/interface.c; issue #16659, #3325).
 *
 * Phase 2: version/error strings without libcurl FFI. HTTP I/O remains #3325.
 */
final class VmCurlCore
{
    /** Bundled libcurl version string reported by curl_version()['version']. */
    public const LIBCURL_VERSION = '8.7.0';

    public static function available(): bool
    {
        return true;
    }

    /** @return array<string, int|string|array<string, bool>> */
    public static function versionInfo(?int $age = null): array
    {
        unset($age);

        $features = 125965879;

        return [
            'version_number' => 526080,
            'version' => self::LIBCURL_VERSION,
            'ssl_version_number' => 0,
            'ssl_version' => 'OpenSSL/3.0.13',
            'libz_version' => '1.3',
            'host' => 'x86_64-pc-linux-gnu',
            'features' => $features,
            'feature_list' => self::featureList($features),
        ];
    }

    /**
     * Derive feature_list bool map from bitmask (php-src ext/curl/interface.c PHP_FUNCTION(curl_version)).
     *
     * @return array<string, bool>
     */
    public static function featureList(int $features): array
    {
        $list = [];
        foreach (CurlConstants::VERSION_FEATURE_BITS as $name => $bit) {
            $shortName = \strtolower(\substr($name, \strlen('CURL_VERSION_')));
            $list[$shortName] = ($features & $bit) !== 0;
        }

        return $list;
    }

    public static function versionArray(?int $age = null): HashTable
    {
        $ht = new HashTable();
        foreach (self::versionInfo($age) as $key => $value) {
            $slot = new Variable();
            if (\is_array($value)) {
                $inner = new HashTable();
                foreach ($value as $k => $v) {
                    $s = new Variable();
                    if (\is_bool($v)) {
                        $s->bool($v);
                    } elseif (\is_int($v)) {
                        $s->int($v);
                    } else {
                        $s->string((string) $v);
                    }
                    $inner->add($k, $s);
                }
                $slot->array($inner);
            } elseif (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string($value);
            }
            $ht->add($key, $slot);
        }

        return $ht;
    }

    public static function easyStrerror(int $code): ?string
    {
        return self::EASY_ERRORS[$code] ?? null;
    }

    public static function multiStrerror(int $code): ?string
    {
        return self::MULTI_ERRORS[$code] ?? null;
    }

    /**
     * libcurl curl_share_strerror() — php-src curl_share_strerror() (#20531).
     *
     * Unknown codes return "CURLSHcode unknown" (libcurl never returns NULL for these).
     */
    public static function shareStrerror(int $code): string
    {
        return self::SHARE_ERRORS[$code] ?? 'CURLSHcode unknown';
    }

    /** @var array<int, string> php-src curl_easy_strerror / libcurl CURLE_* */
    private const EASY_ERRORS = [
        0 => 'No error',
        1 => 'Unsupported protocol',
        2 => 'Failed initialization',
        3 => 'URL malformat',
        5 => 'Could not resolve proxy',
        6 => 'Could not resolve host',
        7 => 'Could not connect',
        22 => 'HTTP returned error',
        23 => 'Failed writing received data to disk/application',
        27 => 'Out of memory',
        28 => 'Timeout was reached',
        35 => 'SSL connect error',
        47 => 'Too many redirects',
        56 => 'Recv error',
    ];

    /** @var array<int, string> php-src curl_multi_strerror / CURLM_* */
    private const MULTI_ERRORS = [
        0 => 'No error',
        1 => 'Invalid multi handle',
        2 => 'Invalid easy handle',
        3 => 'Out of memory',
        4 => 'Internal error',
    ];

    /** @var array<int, string> php-src curl_share_strerror / CURLSHE_* (#20531) */
    private const SHARE_ERRORS = [
        0 => 'No error',
        1 => 'Unknown share option',
        2 => 'Share currently in use',
        3 => 'Invalid share handle',
        4 => 'Out of memory',
        5 => 'Feature not enabled in this library',
    ];
}
