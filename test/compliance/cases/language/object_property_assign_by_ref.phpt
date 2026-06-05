--TEST--
Language: object property assign-by-reference must alias (issue #5370, Zend/zend_object_handlers.c)
--FILE--
<?php
class C {
    public $x;
}
$o = new C;
$v = 1;
$o->x =& $v;
$v = 5;
var_export($o->x);
echo "\n";
$v = 1;
$o->x =& $v;
$o->x = 9;
var_export($v);
echo "\n";
class Dyn {}
$o2 = new Dyn;
$w = 2;
$o2->p =& $w;
$w = 7;
var_export($o2->p);
echo "\n";
--EXPECT--
5
9
7
