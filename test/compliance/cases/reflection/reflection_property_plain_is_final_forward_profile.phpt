--TEST--
ReflectionProperty::isFinal() for final plain (hook-less) properties (#22341, #22241, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class T {
    public final string $x = "a";
    public string $y = "b";
    protected final int $z = 3;
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
  2 => true,
)
