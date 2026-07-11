--TEST--
stdlib http_response_code() accepts status outside 100–599 (#12153, ext/standard/head.c)
--FILE--
<?php
var_export(http_response_code(99));
echo "\n";
var_export(http_response_code(600));
echo "\n";
var_export(http_response_code(0));
echo "\n";
--EXPECT--
true
99
600
