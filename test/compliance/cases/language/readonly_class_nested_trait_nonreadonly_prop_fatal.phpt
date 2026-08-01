--TEST--
Language: readonly class + nested trait non-readonly property (#26592, zend_traits.c)
--FILE--
<?php
trait Inner {
    public int $x;
}
trait T {
    use Inner;
}
readonly class R {
    use T;
}
echo "COMPILED\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Readonly class R cannot use trait with a non-readonly property T::$x in %s on line %d
