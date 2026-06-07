--TEST--
Language: asymmetric visibility — explicit read+set modifiers compile (#7308, RFC asymmetric-visibility-v2)
--FILE--
<?php
class A {
    public private(set) string $x = 'a';
}
class B {
    public protected(set) string $y = 'b';
}
class C {
    protected private(set) string $z = 'c';

    public function readZ(): string {
        return $this->z;
    }
}
$a = new A();
$b = new B();
$c = new C();
echo $a->x, "\n", $b->y, "\n", $c->readZ(), "\n";
$a->x = 'fail';
--EXPECT--
a
b
c
--EXPECT_EXIT--
255
