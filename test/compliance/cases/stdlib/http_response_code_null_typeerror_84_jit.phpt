--TEST--
stdlib http_response_code(null) — TypeError JIT on PROFILE=8.4 (#20962, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    var_export(http_response_code(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
http_response_code(404);
try {
    var_export(http_response_code(null));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_export(http_response_code());
echo " get-ok\n";
--EXPECT--
http_response_code(): Argument #1 ($response_code) must be of type int, null given
http_response_code(): Argument #1 ($response_code) must be of type int, null given
404 get-ok
