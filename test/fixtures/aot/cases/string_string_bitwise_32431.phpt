--TEST--
AOT: string⊙string bitwise byte-wise (#32431, zend_operators.c bitwise_*_function)
--FILE--
<?php
echo bin2hex('a' ^ 'b'), PHP_EOL;
var_dump('AB' & 'A');
var_dump('A' | 'BC');
echo bin2hex('AB' ^ 'C'), PHP_EOL;
var_dump('' | 'x');
var_dump('xy' & '');
var_dump('7' & '3');
--EXPECT--
03
string(1) "A"
string(2) "CC"
02
string(1) "x"
string(0) ""
string(1) "3"
--EXPECT_EXIT--
0
