--TEST--
AOT: numeric-string ⊙ native float +/−/*/÷/<=> (#32325, Zend/zend_operators.c)
--FILE--
<?php
$s = '5';
$f = 1.5;
var_dump($s + $f);
var_dump($f + $s);
var_dump('5.5' * 2.0);
var_dump($s - $f);
var_dump('10' / 4.0);
var_dump($s <=> $f);
echo ($s > $f) ? "gt\n" : "ngt\n";
--EXPECT--
float(6.5)
float(6.5)
float(11)
float(3.5)
float(2.5)
int(1)
gt
