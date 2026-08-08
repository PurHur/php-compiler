--TEST--
stdlib ResponseCode phantom absent; http_response_code() int only (#28931, re-#7322)
--FILE--
<?php
var_export(enum_exists('ResponseCode', false));
echo "\n";
var_export(http_response_code(404));
echo "\n";
var_export(http_response_code());
echo "\n";
var_export(http_response_code(500));
echo "\n";
var_export(http_response_code());
echo "\n";
enum Es: string { case B = 'hi'; }
try {
    http_response_code(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
true
404
404
500
http_response_code(): Argument #1 ($response_code) must be of type int, Es given
