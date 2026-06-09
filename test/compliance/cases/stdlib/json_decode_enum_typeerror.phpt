--TEST--
stdlib json_decode() — enum case json operand TypeError (#5907, ext/json/php_json.c)
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = '[]'; }
try {
    json_decode(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
json_decode(): Argument #1 ($json) must be of type string, E given
