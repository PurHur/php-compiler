--TEST--
Stdlib: attribute_exists() — enum case operands TypeError (#6468, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'Demo'; case B = 'AllowDynamicProperties'; }

#[\AllowDynamicProperties]
class Demo {}

try {
    var_export(attribute_exists(E::A, AllowDynamicProperties::class));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    var_export(attribute_exists('AllowDynamicProperties', 42));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
attribute_exists(): Argument #1 ($attribute) must be of type string, E given
attribute_exists(): Argument #2 ($object) must be of type object|string, int given
