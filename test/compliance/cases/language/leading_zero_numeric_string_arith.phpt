--TEST--
Language: leading-zero numeric string arithmetic stays int (zend_operators.c, #22823)
--FILE--
<?php
$a = "010" + 0;
var_export($a);
echo ' ', gettype($a), "\n";
$b = +"010";
var_export($b);
echo ' ', gettype($b), "\n";
$c = "1e2" + 0;
var_export($c);
echo ' ', gettype($c), "\n";
$d = "010.5" + 0;
var_export($d);
echo ' ', gettype($d), "\n";
$e = "0010" + 0;
var_export($e);
echo ' ', gettype($e), "\n";
$f = "9223372036854775808" + 0;
var_export($f);
echo ' ', gettype($f), "\n";
$s = "010";
$g = $s + 0;
var_export($g);
echo ' ', gettype($g), "\n";
--EXPECT--
10 integer
10 integer
100.0 double
10.5 double
10 integer
9.223372036854776E+18 double
10 integer
