--TEST--
AOT is_iterable() — array and Iterator object (#3313)
--FILE--
<?php
class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
$o = new C();
echo is_iterable([1, 2]) ? 'ay' : 'an', "\n";
echo is_iterable($o) ? 'oy' : 'on', "\n";
echo iterator_count([1, 2, 3]), "\n";
--EXPECT--
ay
oy
3
