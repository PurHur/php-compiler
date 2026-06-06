--TEST--
Intersection return type on named function and method (#6499)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}

function named(): A&B {
    return new C();
}

class D {
    public function m(): A&B {
        return new C();
    }
}

$arrow = fn(): A&B => new C();

echo get_class(named()), "\n";
echo get_class((new D())->m()), "\n";
echo get_class($arrow()), "\n";
?>
--EXPECT--
C
C
C
