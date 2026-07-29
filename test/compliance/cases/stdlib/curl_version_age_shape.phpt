--TEST--
stdlib curl_version() Zend shape - age/protocols/ares (#24463)
--FILE--
<?php
declare(strict_types=1);

$v = curl_version();
echo array_key_exists('age', $v) ? "age_present\n" : "age_missing\n";
echo array_key_exists('protocols', $v) ? "protocols_present\n" : "protocols_missing\n";
echo is_array($v['protocols'] ?? null) && count($v['protocols']) > 0 ? "protocols_nonempty\n" : "protocols_empty\n";
echo array_key_exists('ares', $v) ? "ares_present\n" : "ares_missing\n";
echo array_key_exists('ares_num', $v) ? "ares_num_present\n" : "ares_num_missing\n";
echo array_key_exists('libidn', $v) ? "libidn_present\n" : "libidn_missing\n";
echo array_key_exists('libssh_version', $v) ? "libssh_present\n" : "libssh_missing\n";
echo array_key_exists('brotli_version', $v) ? "brotli_present\n" : "brotli_missing\n";
echo array_key_exists('feature_list', $v) ? "feature_list_present\n" : "feature_list_missing\n";
echo defined('CURLVERSION_NOW') && CURLVERSION_NOW === $v['age'] ? "age_matches_CURLVERSION_NOW\n" : "age_CURLVERSION_mismatch\n";
--EXPECT--
age_present
protocols_present
protocols_nonempty
ares_present
ares_num_present
libidn_present
libssh_present
brotli_present
feature_list_present
age_matches_CURLVERSION_NOW
