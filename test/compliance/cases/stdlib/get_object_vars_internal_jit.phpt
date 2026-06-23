--TEST--
Stdlib: get_object_vars() on internal objects from global scope — JIT (#10719, ext/standard/var.c)
--JIT--
--FILE--
<?php
var_export(get_object_vars(new Exception('x')));
echo "\n";
var_export(get_object_vars(new DateTime('2020-01-01')));
echo "\n";
class Box {
    public $x = 1;
}
var_export(get_object_vars(new Box()));
echo "\n";
$o = new stdClass();
$o->a = 1;
var_export(get_object_vars($o));
echo "\n";
--EXPECT--
array (
)
array (
)
array (
  'x' => 1,
)
array (
  'a' => 1,
)
