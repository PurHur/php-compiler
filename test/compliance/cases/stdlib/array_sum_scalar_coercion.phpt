--TEST--
stdlib array_sum() — scalar element coercion like Zend (#4278, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_dump(array_sum([1, 'x']));
var_dump(array_sum([true, false]));
var_dump(array_sum([null, 1]));
var_dump(array_sum(['x']));
?>
--EXPECT--
int(1)
int(1)
int(1)
int(0)
