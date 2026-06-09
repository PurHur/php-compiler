--TEST--
stdlib get_last_response_headers() after HTTP wrapper fetch (issue #7236)
--FILE--
<?php
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(function_exists('http_get_last_response_headers'));
echo "\n";
var_export(get_last_response_headers());
echo "\n";
@file_get_contents('http://example.com');
$h = get_last_response_headers();
echo is_array($h) ? 'yes' : 'no', "\n";
echo isset($h[0]) && is_string($h[0]) ? 'yes' : 'no', "\n";
echo $h === http_get_last_response_headers() ? 'yes' : 'no', "\n";
--EXPECT--
true
true
NULL
yes
yes
yes
