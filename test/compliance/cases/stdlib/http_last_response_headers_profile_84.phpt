--TEST--
stdlib PHP 8.4 profile — http_* registered; get_last_response_headers phantom (#16494, #28412)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('http_get_last_response_headers'));
echo "\n";
var_export(function_exists('http_clear_last_response_headers'));
echo "\n";
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(null === http_get_last_response_headers());
?>
--EXPECT--
true
true
false
true
