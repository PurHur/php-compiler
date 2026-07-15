--TEST--
json json_decode(null) — TypeError on 8.4 forward profile (#18852, ext/json/php_json.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    json_decode(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
json_decode(): Argument #1 ($json) must be of type string, null given
