--TEST--
stdlib http_build_query() — TypeError for null data argument (#11946, ext/standard/http.c)
--FILE--
<?php
try {
    http_build_query(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
http_build_query(): Argument #1 ($data) must be of type array, null given
