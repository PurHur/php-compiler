--TEST--
stdlib curl_version() / curl_strerror() — phase 2 introspection (#16659)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo function_exists('curl_version') ? "version_fn\n" : "version_missing\n";
echo function_exists('curl_strerror') ? "strerror_fn\n" : "strerror_missing\n";
echo function_exists('curl_multi_strerror') ? "multi_strerror_fn\n" : "multi_strerror_missing\n";
echo extension_loaded('curl') ? "ext_loaded\n" : "ext_missing\n";
$v = curl_version();
echo is_array($v) && isset($v['version']) ? $v['version']."\n" : "bad_version\n";
var_export(curl_strerror(CURLE_OK));
echo "\n";
var_export(curl_multi_strerror(CURLM_OK));
echo "\n";
--EXPECT--
version_fn
strerror_fn
multi_strerror_fn
ext_loaded
8.7.0
'No error'
'No error'
