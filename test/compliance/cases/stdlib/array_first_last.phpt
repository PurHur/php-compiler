--TEST--
stdlib array_first() / array_last() — assoc and list (issue #3491 repro)
--FILE--
<?php
$a = ['x' => 1, 'y' => 2];
var_dump(array_first($a));
var_dump(array_last($a));
$list = [10, 20, 30];
var_dump(array_first($list));
var_dump(array_last($list));
--EXPECT--
int(1)
int(2)
int(10)
int(30)
