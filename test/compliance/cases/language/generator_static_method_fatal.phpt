--TEST--
Language: yield in static method — compile-time fatal (#4938)
--FILE--
<?php
class C {
    public static function gen(): Generator {
        yield 1;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
