--TEST--
stdlib array_sum() JIT — scalar element coercion (#4278)
--FILE--
<?php
declare(strict_types=1);
var_dump(array_sum([1, 'x']));
var_dump(array_sum([true, false]));
var_dump(array_sum([null, 1]));
?>
--EXPECT--
int(1)
int(1)
int(1)
