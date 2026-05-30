--TEST--
language: clone readonly class copies property values independently (issue #3430)
--FILE--
<?php
readonly class Box {
    public function __construct(public string $v) {}
}
$a = new Box('a');
$b = clone $a;
echo $a->v, "\n", $b->v, "\n";
--EXPECT--
a
a
