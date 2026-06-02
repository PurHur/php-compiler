--TEST--
Property default new expressions create per-instance objects (issue #3391)
--FILE--
<?php
class Box {
    public stdClass $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
--EXPECT--
1
1
