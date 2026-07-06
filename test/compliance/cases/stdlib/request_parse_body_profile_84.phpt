--TEST--
stdlib PHP 8.4 profile — request_parse_body() + RequestParseBodyException registered (#16927, ext/standard/http.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('request_parse_body'));
echo "\n";
var_export(class_exists('RequestParseBodyException', false));
echo "\n";
?>
--EXPECT--
true
true

