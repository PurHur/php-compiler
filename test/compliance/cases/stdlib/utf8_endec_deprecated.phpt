--TEST--
stdlib utf8_encode()/utf8_decode() E_DEPRECATED on call (#18104, ext/standard/utf8.c)
--FILE--
<?php
utf8_encode('café');
$e = error_get_last();
echo $e['message'], "\n";
utf8_decode('caf%');
$e = error_get_last();
echo $e['message'], "\n";
--EXPECT--
Function utf8_encode() is deprecated
Function utf8_decode() is deprecated
