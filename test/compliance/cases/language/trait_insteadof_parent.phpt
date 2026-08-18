--TEST--
Language: trait insteadof parent class is a compile error — Zend "not a trait" (#32129, zend_inheritance.c)
--FILE--
<?php
class P {}
trait T {
    public function m() {}
}
class C extends P {
    use T { T::m insteadof P; }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECT--
Class P is not a trait, Only traits may be used in 'as' and 'insteadof' statements
