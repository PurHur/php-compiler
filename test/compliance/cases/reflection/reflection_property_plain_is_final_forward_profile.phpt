--TEST--
ReflectionProperty::isFinal()/getModifiers() for plain final properties (#22341, #22364, ext/reflection/php_reflection.c)
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
echo ReflectionProperty::IS_FINAL, "\n";
echo $rx->getModifiers(), "\n";
echo $ry->getModifiers(), "\n";
echo $rz->getModifiers(), "\n";
echo json_encode(Reflection::getModifierNames($rx->getModifiers())), "\n";
echo json_encode(Reflection::getModifierNames($rz->getModifiers())), "\n";
--EXPECT--
true
array (
  0 => true,
  1 => false,
  2 => true,
)
32
33
1
34
["final","public"]
["final","protected"]
