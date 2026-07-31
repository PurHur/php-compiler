--TEST--
Stdlib: CurlHandle / CurlMultiHandle / CurlShareHandle with loaded ext/curl (#7266, #19728, #3325, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
var_export(class_exists('CurlHandle', false));
echo "\n";
var_export(class_exists('CurlMultiHandle', false));
echo "\n";
var_export(class_exists('CurlShareHandle', false));
echo "\n";
var_export(enum_exists('CurlHandle', false));
echo "\n";
--EXPECT--
true
true
true
false
