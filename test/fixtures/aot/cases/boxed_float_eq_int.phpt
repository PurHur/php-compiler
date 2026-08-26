--TEST--
AOT: boxed float == native int (#35213)
--FILE--
<?php
$x = 1.0;
var_dump($x == 1);
var_dump(1 == $x);
var_dump($x != 1);
function f($v)
{
    return $v;
}
var_dump(f(1.0) == 1);
var_dump(f(0.0) == 0);
--EXPECT--
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
