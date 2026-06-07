--TEST--
stdlib ReflectionMethod::hasPrototype()/getPrototype() — PHP 8.3 override introspection (#7262)
--FILE--
<?php
class A {
    public function m(): void {}
    private function p(): void {}
}

class B extends A {
    public function m(): void {}
    public function p(): void {}
    public function n(): void {}
}

class C extends A {}

interface I {
    public function im(): void;
}

class D implements I {
    public function im(): void {}
}

class E extends D {
    public function im(): void {}
}

$rm = new ReflectionMethod(B::class, 'm');
var_export($rm->hasPrototype());
echo "\n";
$proto = $rm->getPrototype();
echo $proto->getName(), "\n";
echo ($proto->getName() === (new ReflectionMethod(A::class, 'm'))->getName()) ? 'A' : '?', "\n";

var_export((new ReflectionMethod(B::class, 'p'))->hasPrototype());
echo "\n";
var_export((new ReflectionMethod(B::class, 'n'))->hasPrototype());
echo "\n";
var_export((new ReflectionMethod(C::class, 'm'))->hasPrototype());
echo "\n";
var_export((new ReflectionMethod(A::class, 'm'))->hasPrototype());
echo "\n";

var_export((new ReflectionMethod(D::class, 'im'))->hasPrototype());
echo "\n";
var_export((new ReflectionMethod(E::class, 'im'))->hasPrototype());
echo "\n";
$protoIm = (new ReflectionMethod(E::class, 'im'))->getPrototype();
echo ($protoIm->getName() === (new ReflectionMethod(I::class, 'im'))->getName()) ? 'I' : '?', "\n";

try {
    (new ReflectionMethod(B::class, 'n'))->getPrototype();
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
m
A
false
false
false
false
true
true
I
Method B::n does not have a prototype
