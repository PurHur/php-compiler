--TEST--
stdlib http_response_code() foreign enum case — TypeError Zend shape (#6037, ext/standard/head.c)
--FILE--
<?php
enum Status: int { case Ok = 200; }
try {
    http_response_code(Status::Ok);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
var_export(http_response_code(204));
echo "\n";
var_export(http_response_code());
echo "\n";
--EXPECT--
TypeError
http_response_code(): Argument #1 ($response_code) must be of type int, Status given
true
204
