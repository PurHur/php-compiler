--TEST--
stdlib property_exists() — enum case property name TypeError (#9935, ext/standard/basic_functions.c)
--FILE--
<?php
enum E: int { case A = 1; }
$o = new stdClass();
try {
    property_exists($o, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
property_exists(): Argument #2 ($property) must be of type string, E given
