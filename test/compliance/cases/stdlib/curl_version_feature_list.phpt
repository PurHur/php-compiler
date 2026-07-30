--TEST--
stdlib curl_version() feature_list + CURL_VERSION_* on PROFILE=8.4 (#21337, #25357)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$v = curl_version();
echo array_key_exists('feature_list', $v) ? "feature_list_present\n" : "feature_list_missing\n";
$fl = $v['feature_list'];
echo is_array($fl) ? "is_array\n" : "not_array\n";

// Core feature keys must exist as booleans
foreach (['ipv6', 'ssl', 'libz', 'brotli', 'zstd', 'http2', 'http3', 'hsts', 'altsvc'] as $k) {
    echo array_key_exists($k, $fl) ? "$k=present\n" : "$k=missing\n";
}

// CURL_VERSION_* constants defined with correct values
echo defined('CURL_VERSION_IPV6') && CURL_VERSION_IPV6 === 1 ? "IPV6_OK\n" : "IPV6_BAD\n";
echo defined('CURL_VERSION_SSL') && CURL_VERSION_SSL === 4 ? "SSL_OK\n" : "SSL_BAD\n";
echo defined('CURL_VERSION_HTTP2') && CURL_VERSION_HTTP2 === 65536 ? "HTTP2_OK\n" : "HTTP2_BAD\n";
echo defined('CURL_VERSION_HTTP3') && CURL_VERSION_HTTP3 === 33554432 ? "HTTP3_OK\n" : "HTTP3_BAD\n";
echo defined('CURL_VERSION_HSTS') && CURL_VERSION_HSTS === 268435456 ? "HSTS_OK\n" : "HSTS_BAD\n";
echo defined('CURL_VERSION_ALTSVC') && CURL_VERSION_ALTSVC === 16777216 ? "ALTSVC_OK\n" : "ALTSVC_BAD\n";
echo defined('CURL_VERSION_BROTLI') && CURL_VERSION_BROTLI === 8388608 ? "BROTLI_OK\n" : "BROTLI_BAD\n";
echo defined('CURL_VERSION_ZSTD') && CURL_VERSION_ZSTD === 67108864 ? "ZSTD_OK\n" : "ZSTD_BAD\n";

// feature_list values are boolean
echo is_bool($fl['ipv6']) ? "bool_type\n" : "not_bool\n";
--EXPECT--
feature_list_present
is_array
ipv6=present
ssl=present
libz=present
brotli=present
zstd=present
http2=present
http3=present
hsts=present
altsvc=present
IPV6_OK
SSL_OK
HTTP2_OK
HTTP3_OK
HSTS_OK
ALTSVC_OK
BROTLI_OK
ZSTD_OK
bool_type
