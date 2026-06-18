--TEST--
Language: per-property readonly cannot have default value (#3551, #9355)
--FILE--
<?php
class C {
    public readonly int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
