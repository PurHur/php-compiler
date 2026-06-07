--TEST--
DNF parameter type (I1&I2)|C accepts implementing class (#4956)
--FILE--
<?php
interface I1 {}
interface I2 {}
class C implements I1, I2 {}

class DnfParam {
    public function m((I1&I2)|C $x): void {
        echo get_class($x), "\n";
    }
}

(new DnfParam())->m(new C());
?>
--EXPECT--
C
