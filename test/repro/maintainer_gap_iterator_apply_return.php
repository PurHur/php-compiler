<?php
$it = new ArrayIterator([1, 2, 3]);
echo 'break_first=', iterator_apply($it, fn () => false), "\n";

$it = new ArrayIterator([1, 2, 3]);
$n = 0;
echo 'break_middle=', iterator_apply($it, function () use (&$n) {
    ++$n;

    return $n < 2;
}), "\n";

$it = new ArrayIterator([1, 2, 3]);
echo 'all_true=', iterator_apply($it, fn () => true), "\n";

function gen3() {
    yield 1;
    yield 2;
    yield 3;
}
$g = gen3();
echo 'gen_break_first=', iterator_apply($g, fn () => false), "\n";

class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
echo 'custom_break_first=', iterator_apply(new C(), fn () => false), "\n";
