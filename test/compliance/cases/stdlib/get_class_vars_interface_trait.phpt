--TEST--
stdlib get_class_vars() on trait/interface/enum — array not false (#7397, ext/standard/class.c)
--FILE--
<?php
trait T7397 {
    public static string $s = 'hi';
    public int $y = 2;
}
interface I7397 {
    public const C = 1;
}
enum E7397: string {
    case A = 'a';
}
var_export(get_class_vars('T7397'));
echo "\n---\n";
var_export(get_class_vars(I7397::class));
echo "\n---\n";
var_export(get_class_vars(E7397::class));
echo "\n";
--EXPECT--
array (
  'y' => 2,
  's' => 'hi',
)
---
array (
)
---
array (
  'name' => NULL,
  'value' => NULL,
)
