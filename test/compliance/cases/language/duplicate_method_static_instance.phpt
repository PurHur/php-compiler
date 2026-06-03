--TEST--
Language: static and instance method same name — compile-time fatal (#5218)
--FILE--
<?php
class C {
    public static function f() {}
    public function f() {}
}
echo "run\n";
--EXPECT_EXIT--
255
