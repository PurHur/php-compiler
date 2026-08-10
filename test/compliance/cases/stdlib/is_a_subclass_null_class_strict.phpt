--TEST--
stdlib is_a/is_subclass_of(null $class) under strict_types TypeError (#29817, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    is_a('X', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_a(new stdClass(), null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_subclass_of('X', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
is_a(): Argument #2 ($class) must be of type string, null given
is_a(): Argument #2 ($class) must be of type string, null given
is_subclass_of(): Argument #2 ($class) must be of type string, null given
