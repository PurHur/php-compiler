--TEST--
Stdlib: get_mangled_object_vars() on enum case — name/value map (#5674, ext/standard/var.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum Unit { case B; }
var_export(get_mangled_object_vars(E::A));
echo "\n";
var_export(get_mangled_object_vars(Unit::B));
echo "\n";
--EXPECT--
array (
  'name' => 'A',
  'value' => 1,
)
array (
  'name' => 'B',
)
