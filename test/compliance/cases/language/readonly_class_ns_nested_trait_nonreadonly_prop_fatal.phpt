--TEST--
Language: namespaced readonly class + nested trait non-readonly property (#26592)
--FILE--
<?php
namespace N;
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
Fatal error: Readonly class N\R cannot use trait with a non-readonly property N\T::$x in %s on line %d
