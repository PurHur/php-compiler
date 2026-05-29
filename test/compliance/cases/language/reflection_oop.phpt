--TEST--
Language: ReflectionProperty / ReflectionFunction / ReflectionConstant (VM, #3354)
--FILE--
<?php
class C { public int $x = 1; public const FOO = 42; }
function f(): void {}

$r1 = new ReflectionProperty(C::class, 'x');
echo $r1->getName(), $r1->getValue(new C());

$r2 = new ReflectionFunction('f');
echo $r2->getName();

$r3 = new ReflectionConstant(C::class, 'FOO');
echo $r3->getValue();
--EXPECT--
x1f42
