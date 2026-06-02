--TEST--
Language: static readonly property compile-time fatal (#4503)
--FILE--
<?php
class C {
    public static readonly int $p;
}
echo "ok\n";
--EXPECT_EXIT--
255
