--TEST--
Stdlib: get_class_methods() invalid class — TypeError not false (#18110, ext/standard/basic_functions.c)
--FILE--
<?php
try {
    get_class_methods('NoSuch');
    echo "no\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_class_methods(): Argument #1 ($object_or_class) must be an object or a valid class name, string given
