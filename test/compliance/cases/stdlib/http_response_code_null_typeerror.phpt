--TEST--
stdlib http_response_code(null) — false when unset, prior code when set (#18958, ext/standard/head.c)
--FILE--
<?php
var_export(http_response_code(null));
echo "\n";
http_response_code(404);
var_export(http_response_code(null));
echo "\n";
try {
    http_response_code([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
404
http_response_code(): Argument #1 ($response_code) must be of type int, array given
