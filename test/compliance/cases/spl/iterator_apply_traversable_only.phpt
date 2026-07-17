--TEST--
spl iterator_apply() Traversable only — null/array TypeError (#19839, php-src-strict)
--FILE--
<?php
try {
    iterator_apply(null, fn () => true);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    iterator_apply([1, 2], fn () => true);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
echo iterator_apply(new C(), fn () => true), "\n";
echo iterator_count([1, 2, 3]), "\n";
--EXPECT--
iterator_apply(): Argument #1 ($iterator) must be of type Traversable, null given
iterator_apply(): Argument #1 ($iterator) must be of type Traversable, array given
2
3
