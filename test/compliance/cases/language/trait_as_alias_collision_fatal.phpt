--TEST--
Language: trait as-alias onto existing composed name — Zend collision fatal (#25080)
--FILE--
<?php
trait T {
    public function f() { return 1; }
    public function g() { return 2; }
}
class A {
    use T {
        f as private hid;
        g as f;
    }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECT--
Trait method T::g has not been applied as A::f, because of collision with T::f
