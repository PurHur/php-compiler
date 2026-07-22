--TEST--
Language: array dim by-ref residual survives unset of array variable (#22027, Zend/zend_execute.c)
--FILE--
<?php
$a = ["x" => 1];
$b =& $a["x"];
unset($a);
var_export($b);
echo "\n";

$c = ["x" => ["y" => 2]];
$d =& $c["x"]["y"];
unset($c);
var_export($d);
echo "\n";

$e = [10, 20];
$f =& $e[1];
$f = 30;
unset($e);
var_export($f);
echo "\n";

$g = ["k" => 4];
$h =& $g["k"];
$i =& $g["k"];
unset($g);
var_export($h);
echo " ";
var_export($i);
echo "\n";
--EXPECT--
1
2
30
4 4
