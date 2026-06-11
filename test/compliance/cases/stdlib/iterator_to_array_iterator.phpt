--TEST--
iterator_to_array() on Iterator and IteratorAggregate objects (issue #4244)
--FILE--
<?php
class RangeIterator implements Iterator {
    private int $i = 0;
    private int $max;
    public function __construct(int $max) { $this->max = $max; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < $this->max; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}

class RangeAggregate implements IteratorAggregate {
    public function __construct(private int $max) {}
    public function getIterator(): RangeIterator {
        return new RangeIterator($this->max);
    }
}

$a = iterator_to_array(new RangeIterator(3));
echo count($a), "\n";
echo $a[0], $a[1], $a[2], "\n";

$b = iterator_to_array(new RangeAggregate(2));
echo count($b), "\n";
echo $b[0], $b[1], "\n";

try {
    iterator_to_array(42);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
3
012
2
01
iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, int given
