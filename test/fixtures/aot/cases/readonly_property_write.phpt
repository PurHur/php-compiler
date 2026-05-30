--TEST--
AOT: readonly property rejects write after construction (#3149)
--FILE--
<?php
class C {
    public readonly int $x = 1;
}
$c = new C();
$c->x = 2;
--EXPECT--

--EXPECT_EXIT--
255
