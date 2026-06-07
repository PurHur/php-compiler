--TEST--
stdlib get_class_vars() on class using trait — trait static properties included (#7420, ext/standard/class.c)
--FILE--
<?php
trait T7420 {
    public static int $a = 1;
    public static string $b = 'x';
    private static int $hidden = 99;
}
class C7420 {
    use T7420;
    public static int $c = 2;
}
class P7420 {
    public static int $p = 3;
}
class D7420 extends P7420 {}
var_export(get_class_vars(C7420::class));
echo "\n---\n";
var_export(get_class_vars(D7420::class));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 'x',
  'c' => 2,
)
---
array (
  'p' => 3,
)
