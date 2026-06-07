--TEST--
Language: typed property/parameter defaults — incompatible literal type compile-error (#6558)
--FILE--
<?php
class C {
    public static string $s = 123;
}
--EXPECT_EXIT--
255
