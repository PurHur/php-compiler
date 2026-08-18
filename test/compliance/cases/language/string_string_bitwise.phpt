--TEST--
Language: string⊙string bitwise is byte-wise (#32431, Zend/zend_operators.c bitwise_*_function)
--FILE--
<?php
echo ord('a' ^ 'b'), PHP_EOL;
var_dump('AB' & 'A');
var_dump('A' | 'BC');
echo ord('AB' ^ 'C'), PHP_EOL;
var_dump('' | 'x');
var_dump('xy' & '');
var_dump('7' & '3');
?>
--EXPECT--
3
string(1) "A"
string(2) "CC"
2
string(1) "x"
string(0) ""
string(1) "3"
