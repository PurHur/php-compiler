--TEST--
stdlib http_get_last_response_headers() returns null before HTTP fetch (issue #8769, #21172)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(http_get_last_response_headers());
echo "\n";
var_export(function_exists('get_last_response_headers'));
echo "\n";
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
NULL
false
NULL
