--TEST--
stdlib get_class_vars() JIT on class using trait — trait static properties included (#7420)
--JIT--
--FILE--
<?php
trait T7420 {
    public static int $a = 1;
}
class C7420 {
    use T7420;
}
var_export(get_class_vars(C7420::class));
echo "\n";
--EXPECT--
array (
  'a' => 1,
)
