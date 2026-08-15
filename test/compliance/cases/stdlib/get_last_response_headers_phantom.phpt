--TEST--
stdlib get_last_response_headers() phantom — absent from php-src (#28412)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo function_exists('get_last_response_headers') ? "alias-fail\n" : "alias-ok\n";
$defs = get_defined_functions()['internal'];
echo in_array('get_last_response_headers', $defs, true) ? "def-fail\n" : "def-ok\n";
echo function_exists('http_get_last_response_headers') ? "http-get-ok\n" : "http-get-fail\n";
echo function_exists('http_clear_last_response_headers') ? "http-clear-ok\n" : "http-clear-fail\n";
var_export(null === http_get_last_response_headers());
echo "\n";
--EXPECT--
alias-ok
def-ok
http-get-ok
http-clear-ok
true
