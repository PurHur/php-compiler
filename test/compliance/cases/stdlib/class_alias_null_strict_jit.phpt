--TEST--
stdlib class_alias(null) under strict_types TypeError JIT (#29816, Zend/zend_builtin_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    class_alias(null, 'Alias29816Jit');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_alias(): Argument #1 ($class) must be of type string, null given
