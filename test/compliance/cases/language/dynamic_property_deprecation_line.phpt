--TEST--
Language: dynamic property E_DEPRECATED line matches write site (#21953, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);
class A {}
$a = new A;
$a->x = 1;
echo $a->x, "\n";

#[\AllowDynamicProperties]
class B {}
$b = new B;
$b->y = 2;
echo $b->y, "\n";

$s = new stdClass;
$s->z = 3;
echo $s->z, "\n";
--EXPECTF--
PHP Deprecated:  Creation of dynamic property A::$x is deprecated in %s on line 5
1
2
3
