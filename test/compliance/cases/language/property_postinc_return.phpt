--TEST--
Typed instance/static property ++/-- return value in expression (#10123, Zend/zend_execute.c)
--FILE--
<?php
class C {
    public int $x = 1;
    public static int $s = 1;
}

$c = new C();
var_export($c->x++);
echo "\n";
var_export(C::$s++);
echo "\n";
var_export(++$c->x);
echo "\n";
--EXPECT--
1
1
3
