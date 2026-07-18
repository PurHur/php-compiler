--TEST--
ReflectionProperty::isFinal() for final hooked properties (#20511, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class T {
    public final string $x { get => "a"; }
    public string $y { get => "b"; }
    public string $z = "c";
}
$rx = new ReflectionProperty(T::class, "x");
$ry = new ReflectionProperty(T::class, "y");
$rz = new ReflectionProperty(T::class, "z");
var_export(method_exists(ReflectionProperty::class, 'isFinal'));
echo "\n";
var_export([$rx->isFinal(), $ry->isFinal(), $rz->isFinal()]);
echo "\n";
--EXPECT--
true
array (
  0 => true,
  1 => false,
  2 => false,
)
