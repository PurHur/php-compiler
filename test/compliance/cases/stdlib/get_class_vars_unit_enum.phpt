--TEST--
stdlib get_class_vars() on unit enum — name only, no value key (#5012, ext/standard/class.c)
--FILE--
<?php
enum U {
    case A;
}
enum E: string {
    case B = 'b';
}
var_export(get_class_vars(U::class));
echo "\n---\n";
var_export(get_class_vars(E::class));
echo "\n";
--EXPECT--
array (
  'name' => NULL,
)
---
array (
  'name' => NULL,
  'value' => NULL,
)
