--TEST--
Web: http_response_code() get and set
--FILE--
<?php
var_export(http_response_code());
echo "\n";
var_export(http_response_code(404));
echo "\n";
var_export(http_response_code());
echo "\n";
var_export(http_response_code(999));
--EXPECT--
200
404
404
false
