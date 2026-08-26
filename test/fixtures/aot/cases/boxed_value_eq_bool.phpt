--TEST--
AOT: boxed value == native bool (#35220)
--FILE--
<?php
$x = 1.0;
var_dump($x == true);
var_dump(true == $x);
var_dump($x != true);
$y = 0.0;
var_dump($y == false);
$s = '1';
var_dump($s == 1);
var_dump($s == true);
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
bool(false)
