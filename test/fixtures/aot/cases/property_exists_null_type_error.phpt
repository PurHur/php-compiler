--TEST--
AOT: property_exists() — TypeError for null object_or_class is catchable (#33054 / #4787, ext/standard/class.c)
--FILE--
<?php
try {
    var_export(property_exists(null, 'x'));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:property_exists(): Argument #1 ($object_or_class) must be of type object|string, null given
