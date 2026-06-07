--TEST--
Language: assign-by-reference through object property array must alias (issue #7441, zend_operators.c)
--FILE--
<?php
class Box {
    public $a = [1];
}
$box = new Box;
$box->a[] =& $box->a[0];
$box->a[0] = 9;
var_export($box->a[1]);
echo "\n";
class Nest {
    public $n = ['x' => [1]];
}
$nest = new Nest;
$nest->n['x'][] =& $nest->n['x'][0];
$nest->n['x'][0] = 7;
var_export($nest->n['x'][1]);
echo "\n";
--EXPECT--
9
7
