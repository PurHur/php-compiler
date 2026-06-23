--TEST--
AOT: get_object_vars() on internal objects from global scope (#10719)
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
--EXPECT--
array (
)
array (
)
array (
  'x' => 1,
)
