--TEST--
ReflectionProperty::isAbstract() on abstract property hook (#6983, ext/reflection/php_reflection.c)
--FILE--
<?php
abstract class A {
    abstract public string $x { get; }
}
class B extends A {
    public string $x { get => 'ok'; }
}
$r1 = new ReflectionProperty(A::class, 'x');
$r2 = new ReflectionProperty(B::class, 'x');
var_export($r1->isAbstract());
echo "\n";
var_export($r2->isAbstract());
echo "\n";
--EXPECT--
true
false
