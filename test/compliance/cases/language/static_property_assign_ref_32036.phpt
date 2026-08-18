--TEST--
Language: $r = &Class::$prop binds the static slot (#32036, zend_variables.c)
--FILE--
<?php
class C32036
{
    public static $x = 0;
}

$r = &C32036::$x;
$r = 99;
echo C32036::$x, "\n";
var_dump($r);

class T32036
{
    public static int $n = 1;
}

$t = &T32036::$n;
$t = 7;
echo T32036::$n, "\n";
var_dump($t);
--EXPECT--
99
int(99)
7
int(7)
