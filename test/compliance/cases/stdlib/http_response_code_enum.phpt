--TEST--
stdlib ResponseCode enum for http_response_code() (#7322, ext/standard/head.c)
--FILE--
<?php
var_export(enum_exists('ResponseCode', false));
echo "\n";
var_export(ResponseCode::NotFound->name);
echo "\n";
var_export(ResponseCode::NotFound->value);
echo "\n";
var_export(http_response_code(ResponseCode::NotFound));
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
true
'NotFound'
404
true
404
404
500
http_response_code(): Argument #1 ($response_code) must be of type int|ResponseCode|null, Es given
