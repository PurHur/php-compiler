--TEST--
Language: readonly class property cannot have default value (#3551)
--FILE--
<?php
readonly class R2 {
    public int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
