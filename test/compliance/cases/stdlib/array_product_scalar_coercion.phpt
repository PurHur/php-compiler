--TEST--
stdlib array_product() — scalar element coercion like Zend (#4278, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_dump(array_product([1, 'x']));
var_dump(array_product([true, false]));
var_dump(array_product(['x']));
?>
--EXPECT--
int(0)
int(0)
int(0)
