--TEST--
stdlib utf8_encode()/utf8_decode() — E_DEPRECATED on call (#18104, ext/standard/utf8.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
@utf8_encode('café');
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function utf8_encode() is deprecated') ? 'encode_ok' : 'encode_fail';
echo "\n";
@utf8_decode('caf%');
$last = error_get_last();
var_export($last['type'] ?? null);
echo "\n";
echo str_contains($last['message'] ?? '', 'Function utf8_decode() is deprecated') ? 'decode_ok' : 'decode_fail';
echo "\n";
?>
--EXPECT--
8192
encode_ok
8192
decode_ok
