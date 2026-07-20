--TEST--
stdlib http_clear_last_response_headers() clears stored HTTP wrapper headers (issue #7024)
--FILE--
<?php
var_export(function_exists('http_clear_last_response_headers'));
echo "\n";
@file_get_contents('http://example.com');
var_export(http_get_last_response_headers() !== null);
echo "\n";
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
true
true
NULL
NULL
