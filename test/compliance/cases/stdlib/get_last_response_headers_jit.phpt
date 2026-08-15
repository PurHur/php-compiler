--TEST--
stdlib get_last_response_headers() phantom absent on JIT; http_* null without HTTP state (#28412, #21172)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
false
NULL
