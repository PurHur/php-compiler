--TEST--
iterator_count() on user Iterator object after ForeachIterator import (#4547)
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
echo iterator_count(new C()), "\n";
--EXPECT--
2
