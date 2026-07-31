--TEST--
curl_getinfo() content_type / effective_method + CURLINFO_EFFECTIVE_METHOD (#21883, ext/curl/interface.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
error_reporting(E_ALL);
$ch = curl_init('https://example.com');
$info = curl_getinfo($ch);
echo defined('CURLINFO_EFFECTIVE_METHOD') ? 'const_ok' : 'const_missing', "\n";
echo CURLINFO_EFFECTIVE_METHOD, "\n";
echo array_key_exists('content_type', $info) ? 'ct_ok' : 'ct_missing', "\n";
echo array_key_exists('effective_method', $info) ? 'em_ok' : 'em_missing', "\n";
echo null === $info['content_type'] ? 'ct_null' : gettype($info['content_type']), "\n";
echo $info['effective_method'], "\n";
$em = curl_getinfo($ch, CURLINFO_EFFECTIVE_METHOD);
echo $em, "\n";
$ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
echo false === $ct ? 'ct_opt_false' : 'ct_opt_other', "\n";
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
$em2 = curl_getinfo($ch, CURLINFO_EFFECTIVE_METHOD);
echo $em2, "\n";
curl_close($ch);
?>
--EXPECT--
const_ok
1048634
ct_ok
em_ok
ct_null
GET
GET
ct_opt_false
PUT
