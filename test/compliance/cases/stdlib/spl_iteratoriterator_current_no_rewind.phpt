--TEST--
SPL IteratorIterator::current() before rewind() returns NULL (#14687, ext/spl/spl_iterators.c)
--FILE--
<?php
class SimpleIterator implements Iterator
{
    private int $pos = 0;
    /** @var list<int> */
    private array $data = [1, 2, 3];
    public function rewind(): void { $this->pos = 0; }
    public function valid(): bool { return isset($this->data[$this->pos]); }
    public function current(): int { return $this->data[$this->pos]; }
    public function key(): int { return $this->pos; }
    public function next(): void { ++$this->pos; }
}

$outer = new IteratorIterator(new SimpleIterator());
var_export($outer->current());
echo "\n";
var_export($outer->valid());
echo "\n";
var_export($outer->key());
echo "\n";
$outer->rewind();
var_export($outer->current());
echo "\n";
--EXPECT--
NULL
false
NULL
1
