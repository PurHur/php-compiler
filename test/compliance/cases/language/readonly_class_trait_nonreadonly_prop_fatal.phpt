--TEST--
Language: readonly class cannot use trait with non-readonly property (#26592, zend_compile.c)
--FILE--
<?php
trait T {
    public int $x;
}
readonly class R {
    use T;
}
echo "COMPILED\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Readonly class R cannot use trait with a non-readonly property T::$x in %s on line %d
