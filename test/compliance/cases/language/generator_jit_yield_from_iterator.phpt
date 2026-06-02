--TEST--
Generator yield from Iterator / IteratorAggregate via MCJIT (issue #4562)
--FILE--
<?php
class T implements Iterator {
    private int $i = 0;
    public function current() { return $this->i * 10; }
    public function key() { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
class Inner implements Iterator {
    private int $i = 0;
    public function current() { return $this->i + 1; }
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
function gen_from_iterator(): Generator {
    yield from new T();
}
function gen_from_aggregate(): Generator {
    yield from new Outer();
}
foreach (gen_from_iterator() as $v) {
    echo $v, "\n";
}
foreach (gen_from_aggregate() as $k => $v) {
    echo "$k=$v\n";
}
--EXPECT--
0
10
20
k0=1
k1=2
