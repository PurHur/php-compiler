--TEST--
Stdlib: CurlHandle / CurlMultiHandle / CurlShareHandle withheld without ext/curl (#7266, #19728, ext/curl/curl.stub.php)
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
false
false
false
false
