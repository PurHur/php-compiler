--TEST--
Property default new expressions — untyped instance per-object; promoted params compile (#3391, #5362; typed/static rejected #10095, #10693)
--FILE--
<?php
class Box {
    public $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
--EXPECT--
1
1
