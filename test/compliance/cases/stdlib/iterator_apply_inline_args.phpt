--TEST--
stdlib iterator_apply() inline array literal args (#11586)
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
echo iterator_apply($o, fn ($v) => $v + 1, [$o]), "\n";
--EXPECT--
2
