<?php
// #22823 — leading-zero decimal numeric strings stay int under arithmetic (zend_operators.c)
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
