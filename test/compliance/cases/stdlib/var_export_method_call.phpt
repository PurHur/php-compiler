--TEST--
stdlib var_export($it->current(), true) returns exported scalar after stmt-level next() (#17251, ext/standard/var.c)
--FILE--
<?php
$it = new ArrayIterator([1, 2]);
$it->next();
echo var_export($it->current(), true), "\n";
echo var_export((new ArrayIterator([1]))->current(), true), "\n";
--EXPECT--
2
1
--EXPECT_EXIT--
0
