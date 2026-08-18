<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\CompilerVersion;
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

    /**
     * curl_version_info() age / CURLVERSION_NOW for the bundled shape
     * (libcurl curlver.h; php-src ext/curl/interface.c; #24463, #24099).
     */
    public const CURLVERSION_NOW = 10;

    /** Bundled version_num for 8.7.0 — (major<<16)|(minor<<8)|patch. */
    public const LIBCURL_VERSION_NUM = 526080;

    public static function available(): bool
    {
        return true;
    }

    /**
     * Zend-shaped curl_version() payload (php-src ext/curl/interface.c PHP_FUNCTION(curl_version); #24463, #25357).
     *
     * Key order matches php-src: version_number, age, features, [feature_list on 8.4+], ssl_*, version,
     * host, libz, protocols, then age-gated ares/libidn/iconv/libssh/brotli fields.
     * {@code feature_list} is PHP 8.4+ only — withheld on the 8.2 reference profile (#25357).
     *
     * @return array<string, int|string|list<string>|array<string, bool>>
     */
    public static function versionInfo(?int $age = null): array
    {
        $age ??= self::CURLVERSION_NOW;
        $features = 125965879;

        $info = [
            'version_number' => self::LIBCURL_VERSION_NUM,
            'age' => $age,
            'features' => $features,
        ];
        // php-src 8.4.0+: feature_list between features and ssl_version_number (#25357).
        if (CompilerVersion::advertisesCurlVersionFeatureList()) {
            $info['feature_list'] = self::featureList($features);
        }
        $info['ssl_version_number'] = 0;
        $info['version'] = self::LIBCURL_VERSION;
        $info['host'] = 'x86_64-pc-linux-gnu';
        $info['ssl_version'] = 'OpenSSL/3.0.13';
        $info['libz_version'] = '1.3';
        $info['protocols'] = self::bundledProtocols();
        if ($age >= 1) {
            $info['ares'] = '';
            $info['ares_num'] = 0;
        }
        if ($age >= 2) {
            $info['libidn'] = '2.3.7';
        }
        if ($age >= 3) {
            $info['iconv_ver_num'] = 0;
            $info['libssh_version'] = 'libssh/0.10.6/openssl/zlib';
        }
        if ($age >= 4) {
            $info['brotli_ver_num'] = 16781312;
            $info['brotli_version'] = '1.1.0';
        }

        return $info;
    }

    /**
     * Typical libcurl protocol list (numeric keys like curl_version_info()->protocols).
     *
     * @return list<string>
     */
    public static function bundledProtocols(): array
    {
        return [
            'dict', 'file', 'ftp', 'ftps', 'gopher', 'gophers', 'http', 'https',
            'imap', 'imaps', 'ldap', 'ldaps', 'mqtt', 'pop3', 'pop3s',
            'rtmp', 'rtmpe', 'rtmps', 'rtmpt', 'rtmpte', 'rtmpts', 'rtsp',
            'scp', 'sftp', 'smb', 'smbs', 'smtp', 'smtps', 'telnet', 'tftp',
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
                    // protocols use numeric indices; feature_list uses string keys (#24463).
                    $inner->add(\is_int($k) ? (string) $k : $k, $s);
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

    public static function multiStrerror(int $code): ?string
    {
        return self::MULTI_ERRORS[$code] ?? null;
    }

    /**
     * libcurl curl_share_strerror() — php-src curl_share_strerror() (#20531, #32340).
     *
     * Unknown codes return "CURLSHcode unknown" (libcurl never returns NULL for these).
     */
    public static function shareStrerror(int $code): string
    {
        return CurlShareStrerrorJitHelper::message($code);
    }

    /** @var array<int, string> php-src curl_multi_strerror / CURLM_* */
    private const MULTI_ERRORS = [
        0 => 'No error',
        1 => 'Invalid multi handle',
        2 => 'Invalid easy handle',
        3 => 'Out of memory',
        4 => 'Internal error',
    ];
}
