--TEST--
Language: enum cannot redeclare cases() — compile-time fatal (#26502, Zend/zend_enum.c)
--FILE--
<?php
enum E {
    case A;
    public static function cases(): array {
        return [];
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot redeclare E::cases() in %s on line %d
