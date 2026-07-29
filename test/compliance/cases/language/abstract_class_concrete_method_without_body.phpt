--TEST--
Language: abstract class non-abstract method without body — compile fatal (#24906)
--FILE--
<?php
abstract class A {
    public function f();
}
echo "PARSED\n";
--EXPECTF--
Fatal error: Non-abstract method A::f() must contain body in %s on line %d
--EXPECT_EXIT--
255
