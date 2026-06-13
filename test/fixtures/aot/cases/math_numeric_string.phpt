--TEST--
AOT: floor()/ceil()/round()/fmod() numeric-string coercion (#4350)
--FILE--
<?php
echo floor("3.7"), "\n";
echo ceil("3.1"), "\n";
echo round("3.5"), "\n";
echo fmod("5", "2"), "\n";
try {
    floor("abc");
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
3
4
4
1
floor(): Argument #1 ($num) must be of type int|float, string given
--EXPECT_EXIT--
0
