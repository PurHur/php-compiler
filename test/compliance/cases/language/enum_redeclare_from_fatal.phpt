--TEST--
Language: backed enum cannot redeclare from() — compile-time fatal (#26502, Zend/zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    public static function from(int|string $v): static {
        return self::A;
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Cannot redeclare E::from() in %s on line %d
