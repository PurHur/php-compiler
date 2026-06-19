--TEST--
Stdlib: class_has_method/property/constant() basic probes (VM, #9989)
--FILE--
<?php
declare(strict_types=1);

class C { public function m(): void {} }
class D { public int $p = 1; }
class E { public const X = 1; }

class Parent_ {
    public function foo(): void {}
    private function bar(): void {}
    public const C = 1;
    public int $p = 0;
}
class Child extends Parent_ {
    public int $q = 1;
}

echo (function_exists('class_has_method') ? '1' : '0');
echo (class_has_method(C::class, 'm') ? '1' : '0');
echo (class_has_method(C::class, 'missing') ? '1' : '0');
echo (class_has_property(D::class, 'p') ? '1' : '0');
echo (class_has_property(D::class, 'missing') ? '1' : '0');
echo (class_has_constant(E::class, 'X') ? '1' : '0');
echo (class_has_constant(E::class, 'MISSING') ? '1' : '0');
echo (class_has_method(Child::class, 'foo') ? '1' : '0');
echo (class_has_method(Child::class, 'bar') ? '1' : '0');
echo (class_has_property(Child::class, 'q') ? '1' : '0');
echo (class_has_property(Child::class, 'p') ? '1' : '0');
echo (class_has_constant(Child::class, 'C') ? '1' : '0');
echo "\n";
--EXPECT--
110101010111
