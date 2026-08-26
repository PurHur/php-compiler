--TEST--
Language: boxed value == / != native bool + numeric string == long (#35220)
--FILE--
<?php
$x = 1.0;
var_dump($x == true);
var_dump(true == $x);
var_dump($x != true);
$y = 0.0;
var_dump($y == false);
var_dump(false == $y);
$i = 1;
var_dump($i == true);
$s = '1';
var_dump($s == 1);
var_dump($s == true);
$z = '0';
var_dump($z == false);
var_dump($z == 0);
function f($v)
{
    return $v;
}
var_dump(f(1.0) == true);
var_dump(f(0.0) == false);
$empty = '';
var_dump($empty == false);
var_dump($empty == 0);
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
