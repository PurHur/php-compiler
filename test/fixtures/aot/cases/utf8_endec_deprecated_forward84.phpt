--TEST--
AOT: utf8_encode()/utf8_decode() E_DEPRECATED since/php.net wording under PROFILE=8.4 (#31176)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@utf8_encode('a');
$e = error_get_last();
$expect = 'Function utf8_encode() is deprecated since 8.2, visit the php.net documentation for various alternatives';
echo (($e['type'] ?? 0) === E_DEPRECATED) ? 'enc_type_ok' : 'enc_type_fail';
echo "\n";
echo (($e['message'] ?? '') === $expect) ? 'enc_msg_ok' : ('enc_msg_fail:'.($e['message'] ?? ''));
echo "\n";
@utf8_decode('a');
$e = error_get_last();
$expect = 'Function utf8_decode() is deprecated since 8.2, visit the php.net documentation for various alternatives';
echo (($e['type'] ?? 0) === E_DEPRECATED) ? 'dec_type_ok' : 'dec_type_fail';
echo "\n";
echo (($e['message'] ?? '') === $expect) ? 'dec_msg_ok' : ('dec_msg_fail:'.($e['message'] ?? ''));
echo "\n";
--EXPECT--
enc_type_ok
enc_msg_ok
dec_type_ok
dec_msg_ok
--EXPECT_EXIT--
0
