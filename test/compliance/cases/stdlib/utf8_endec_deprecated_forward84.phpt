--TEST--
stdlib utf8_encode()/utf8_decode() E_DEPRECATED since/php.net wording under PROFILE=8.4 (#31176)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
utf8_encode('café');
$e = error_get_last();
echo $e['message'], "\n";
utf8_decode('caf%');
$e = error_get_last();
echo $e['message'], "\n";
--EXPECT--
Function utf8_encode() is deprecated since 8.2, visit the php.net documentation for various alternatives
Function utf8_decode() is deprecated since 8.2, visit the php.net documentation for various alternatives
