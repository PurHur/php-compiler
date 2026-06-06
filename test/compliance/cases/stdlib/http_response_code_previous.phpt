--TEST--
stdlib http_response_code() returns true then prior code on set (#6591, ext/standard/head.c)
--FILE--
<?php
var_export(http_response_code(404));
echo "\n";
var_export(http_response_code());
echo "\n";
var_export(http_response_code(500));
echo "\n";
var_export(http_response_code());
echo "\n";
--EXPECT--
true
404
404
500
