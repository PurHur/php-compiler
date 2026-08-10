--TEST--
stdlib class_alias(null) under strict_types TypeError (#29816, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    class_alias(null, 'Alias29816');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    class_alias('stdClass', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_alias(): Argument #1 ($class) must be of type string, null given
class_alias(): Argument #2 ($alias) must be of type string, null given
