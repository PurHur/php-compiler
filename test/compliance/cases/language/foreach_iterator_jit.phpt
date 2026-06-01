--TEST--
foreach over Iterator object (JIT, #4067)
--FILE--
<?php
class R implements Iterator {
    private int $i = 0;
    private array $a = [10, 20];
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current() { return $this->a[$this->i]; }
    public function key() { return $this->i; }
    public function next(): void { ++$this->i; }
}
$sum = 0;
foreach (new R() as $v) {
    $sum += $v;
}
echo $sum, "\n";
--EXPECT--
30
