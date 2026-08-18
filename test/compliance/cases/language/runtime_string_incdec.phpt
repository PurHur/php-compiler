--TEST--
Language: runtime string ++ is increment_string / numeric promote (#32435, Zend/zend_operators.c)
--FILE--
<?php
function f() { return 'a'; }
$s = f();
$s++;
echo $s, PHP_EOL;
function g() { return '9'; }
$n = g();
$n++;
var_dump($n);
function h() { return 'z'; }
$z = h();
$z++;
echo $z, PHP_EOL;
?>
--EXPECT--
b
int(10)
aa
