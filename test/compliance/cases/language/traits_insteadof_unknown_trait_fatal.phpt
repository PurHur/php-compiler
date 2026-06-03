--TEST--
Language: traits — insteadof unknown trait is a fatal compile error
--FILE--
<?php
trait T1 { public function f() {} }
trait T2 { public function f() {} }
class C {
    use T1, T2 { T1::f insteadof T3; }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECT--
Could not find trait T3

