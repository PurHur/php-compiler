--TEST--
Function/closure static local return $n++ returns pre-increment value (issue #9375, Zend/zend_execute.c)
--FILE--
<?php
function f(): int
{
    static $n = 10;
    return $n++;
}
var_dump(f());
var_dump(f());

$c = function (): int {
    static $n = 10;
    return $n++;
};
var_dump($c());
var_dump($c());
--EXPECT--
int(10)
int(11)
int(10)
int(11)
