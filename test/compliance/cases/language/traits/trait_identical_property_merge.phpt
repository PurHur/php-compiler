--TEST--
Language: identical trait+class instance property merges (#22850, zend_inheritance.c)
--FILE--
<?php
trait T { public $x = 1; }
class C { use T; public $x = 1; }
echo (new C)->x, "\n";

trait T2 { public static $y = 2; }
class D { use T2; public static $y = 2; }
echo D::$y, "\n";

trait T3 { public $z = 1; }
trait U3 { public $z = 1; }
class E { use T3, U3; }
echo (new E)->z, "\n";

trait T4 { public $w; }
class F { use T4; public $w = null; }
var_dump((new F)->w);
--EXPECT--
1
2
1
NULL
