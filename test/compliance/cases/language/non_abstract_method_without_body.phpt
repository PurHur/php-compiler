--TEST--
Language: non-abstract method without body — compile fatal (#24906)
--FILE--
<?php
class C {
    public function f();
}
echo "PARSED\n";
(new C)->f();
echo "CALLED\n";
--EXPECTF--
Fatal error: Non-abstract method C::f() must contain body in %s on line %d
--EXPECT_EXIT--
255
