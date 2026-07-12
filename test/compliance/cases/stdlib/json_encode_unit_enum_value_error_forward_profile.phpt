--TEST--
stdlib json_encode() unit enum — ValueError on PHP 8.3+ forward profile (#5683, ext/json/php_json.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum UE { case A; }

try {
    json_encode(UE::A);
    echo "no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
json_encode(): Argument #1 ($value) contains an invalid JSON type
