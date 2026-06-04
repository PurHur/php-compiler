--TEST--
Property default new expressions — instance per-object and static shared (issues #3391, #5362)
--FILE--
<?php
class Box {
    public stdClass $inner = new stdClass();
    public static stdClass $shared = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
echo (Box::$shared instanceof stdClass) ? "1\n" : "0\n";
echo (Box::$shared === Box::$shared) ? "1\n" : "0\n";
class Holder {
    public function __construct(public array $items = []) {}
}
class WithArgs {
    public Holder $h = new Holder([]);
}
$w = new WithArgs();
echo ($w->h instanceof Holder && $w->h->items === []) ? "1\n" : "0\n";
--EXPECT--
1
1
1
1
1
