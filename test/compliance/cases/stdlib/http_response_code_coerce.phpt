--TEST--
stdlib http_response_code() — numeric-string coercion + TypeError (#4454, ext/standard/head.c)
--FILE--
<?php
http_response_code("404");
echo http_response_code(), "\n";
http_response_code(null);
echo http_response_code() === 404 ? "ok\n" : "fail\n";
try {
    http_response_code([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
404
ok
http_response_code(): Argument #1 ($response_code) must be of type int, array given
