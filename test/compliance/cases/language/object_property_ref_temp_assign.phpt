--TEST--
Language: object property ref via temp variable must alias (issue #13559, Zend/zend_execute.c)
--FILE--
<?php
class C {
    public int $prop = 1;
}
$obj = new C();
$ref = &$obj->prop;
$ref = 5;
var_export($obj->prop);
echo "\n";
function bump(int &$x): void {
    $x = 9;
}
$obj->prop = 1;
bump($obj->prop);
var_export($obj->prop);
echo "\n";
class Dyn {}
$o = new Dyn;
$o->p = 2;
$r = &$o->p;
$r = 7;
var_export($o->p);
echo "\n";
--EXPECT--
5
9
7
