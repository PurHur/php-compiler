--TEST--
stdlib var_export() — object __set_state property indent matches Zend (#23742, ext/standard/var.c)
--FILE--
<?php
class O {
    public $a = 1;
    private $b = 2;
}
$object = var_export(new O(), true);
$array = var_export([1, 2], true);
echo str_contains($object, "   'a' => 1,") ? "object_indent=ok\n" : "object_indent=fail\n";
echo str_contains($array, "  0 => 1,") ? "array_indent=ok\n" : "array_indent=fail\n";
--EXPECT--
object_indent=ok
array_indent=ok
