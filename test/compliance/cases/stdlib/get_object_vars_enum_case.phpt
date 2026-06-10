--TEST--
Stdlib: get_object_vars() on enum case objects — name/value keys (#4809, ext/standard/var.c)
--FILE--
<?php
enum Color: string { case Red = 'red'; }
enum Unit { case A; }
var_export(get_object_vars(Color::Red));
echo "\n";
var_export(get_object_vars(Unit::A));
echo "\n";
--EXPECT--
array (
  'name' => 'Red',
  'value' => 'red',
)
array (
  'name' => 'A',
)
