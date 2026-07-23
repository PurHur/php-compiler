--TEST--
stdlib json_validate() — enum case json operand TypeError (#5999, ext/json/php_json.c, #22544)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
declare(strict_types=1);
enum E: string { case A = 'x'; }
try {
    json_validate(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
json_validate(): Argument #1 ($json) must be of type string, E given
