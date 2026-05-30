--TEST--
AOT: readonly property with compile-time default (#3149)
--FILE--
<?php
class C {
    public readonly int $x = 42;
}
echo (new C())->x, "\n";
--EXPECT--
42
--EXPECT_EXIT--
0
