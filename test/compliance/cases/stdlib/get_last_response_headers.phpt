--TEST--
stdlib get_last_response_headers() phantom absent; http_* after HTTP wrapper fetch (#28412, #7236)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(function_exists('http_get_last_response_headers'));
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
@file_get_contents('http://example.com');
$h = http_get_last_response_headers();
echo is_array($h) ? 'yes' : 'no', "\n";
echo isset($h[0]) && is_string($h[0]) ? 'yes' : 'no', "\n";
--EXPECT--
false
true
NULL
yes
yes
