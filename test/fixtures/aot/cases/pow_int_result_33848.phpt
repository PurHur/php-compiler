--TEST--
AOT: pow(int,int) returns int like Zend (#33848 / leftover #3678)
--FILE--
<?php
var_dump(pow(2, 3));
var_dump(pow(-2, 3));
var_dump(pow(2.0, 3));
var_dump(2 ** 3);
--EXPECT--
int(8)
int(-8)
float(8)
int(8)
--EXPECT_EXIT--
0
