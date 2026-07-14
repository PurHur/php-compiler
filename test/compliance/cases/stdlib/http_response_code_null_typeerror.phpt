--TEST--
stdlib http_response_code(null) — TypeError not false (#18933, ext/standard/head.c)
--FILE--
<?php
try {
    http_response_code(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
http_response_code(): Argument #1 ($response_code) must be of type int, null given
