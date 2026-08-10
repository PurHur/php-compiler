--TEST--
Language: readonly class typed static property — Static property cannot be readonly (#29980)
--FILE--
<?php
readonly class R {
    public static int $x;
}
echo "ok\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Static property R::$x cannot be readonly
