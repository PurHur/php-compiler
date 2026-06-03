--TEST--
Language: duplicate final/non-final method — compile-time fatal (#5218)
--FILE--
<?php
class C {
    final public function f() {}
    public function f() {}
}
echo "run\n";
--EXPECT_EXIT--
255
