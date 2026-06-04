--TEST--
Language: array element assign by reference — $arr[] = &$var must alias (issue #5349, Zend/zend_operators.c)
--FILE--
<?php
$v = 1;
$a = [];
$a[] =& $v;
$v = 9;
var_export($a);
echo "\n";
$outer = [];
$inner = 1;
$outer['k'] =& $inner;
$inner = 2;
var_export($outer);
echo "\n";
$o = new stdClass;
$o->p = 1;
$b = [];
$b[] =& $o->p;
$o->p = 3;
var_export($b);
echo "\n";
--EXPECT--
array (
  0 => 9,
)
array (
  'k' => 2,
)
array (
  0 => 3,
)
