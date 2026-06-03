--TEST--
Language: traits — aliasing missing method is a fatal compile error
--FILE--
<?php
trait T1 { public function f() {} }
class C {
    use T1 { T1::g as gg; }
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECT--
An alias was defined for T1::g but this method does not exist

