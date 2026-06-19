--TEST--
Property default new expressions — instance per-object (issues #3391, #5362; static rejected #10095)
--FILE--
<?php
class Box {
    public stdClass $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
class Holder {
    public function __construct(public array $items = []) {}
}
class WithArgs {
    public Holder $h = new Holder([]);
}
$w = new WithArgs();
echo ($w->h instanceof Holder && $w->h->items === []) ? "1\n" : "0\n";
class WithDateTime {
    public DateTime $d = new DateTime('2020-01-01');
}
$dt = new WithDateTime();
echo $dt->d->format('Y'), "\n";
--EXPECT--
1
1
1
2020
