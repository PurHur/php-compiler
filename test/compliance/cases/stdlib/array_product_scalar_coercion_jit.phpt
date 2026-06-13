--TEST--
stdlib array_product() JIT — scalar element coercion (#4278)
--FILE--
<?php
declare(strict_types=1);
var_dump(array_product([1, 'x']));
var_dump(array_product([true, false]));
?>
--EXPECT--
int(0)
int(0)
