--TEST--
foreach over IteratorAggregate object (VM, #3234)
--FILE--
<?php
class Inner implements Iterator {
    private int $i = 0;
    public function current() { return $this->i * 10; }
    public function key() { return 'k'.$this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
}
class Outer implements IteratorAggregate {
    public function getIterator() {
        return new Inner();
    }
}
foreach (new Outer() as $k => $v) {
    echo "$k=$v\n";
}
--EXPECT--
k0=0
k1=10
