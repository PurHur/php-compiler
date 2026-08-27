<?php
// AOT: boxed string &|^ must be byte-wise, not int coerce (#35312).
// php-src: Zend/zend_operators.c bitwise_and/or/xor_function string/string path
$a = 'a';
$b = 'b';
var_dump($a & $b);
echo bin2hex($a | $b), "\n";
echo bin2hex($a ^ $b), "\n";
$c = '12';
$d = '3';
var_dump($c & $d);
var_dump('a' & 'b');
