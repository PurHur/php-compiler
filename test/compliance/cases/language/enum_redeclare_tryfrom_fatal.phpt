--TEST--
Language: backed enum cannot redeclare tryFrom() — compile-time fatal (#26502, Zend/zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    public static function tryFrom(int|string $v): ?static {
        return null;
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot redeclare E::tryfrom() in %s on line %d
