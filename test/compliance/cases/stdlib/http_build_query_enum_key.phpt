--TEST--
stdlib http_build_query() enum case array keys TypeError (#9791, ext/standard/http.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    http_build_query([E::A => 'v']);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Illegal offset type
