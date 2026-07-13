--TEST--
json json_decode() JIT rejects null $json (#18601, ext/json/php_json.c)
--FILE--
<?php
try {
    json_decode(null);
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
json_decode(): Argument #1 ($json) must be of type string, null given
