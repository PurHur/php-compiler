--TEST--
stdlib property_exists() — TypeError for null object_or_class (#4787, ext/standard/class.c)
--FILE--
<?php
try {
    property_exists(null, 'x');
} catch (Throwable $e) {
    echo $e::class, "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
property_exists(): Argument #1 ($object_or_class) must be of type object|string, null given
