<?php
/**
 * Issue #14687 — IteratorIterator::current() before rewind() must return NULL (php-src spl_iterators.c).
 */
declare(strict_types=1);

class SimpleIterator implements Iterator
{
    private int $pos = 0;

    /** @var list<int> */
    private array $data = [1, 2, 3];

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function valid(): bool
    {
        return isset($this->data[$this->pos]);
    }

    public function current(): int
    {
        return $this->data[$this->pos];
    }

    public function key(): int
    {
        return $this->pos;
    }

    public function next(): void
    {
        ++$this->pos;
    }
}

$inner = new SimpleIterator();
$outer = new IteratorIterator($inner);

var_export($outer->current());
echo "\n";
var_export($outer->valid());
echo "\n";
var_export($outer->key());
echo "\n";

$outer->rewind();
var_export($outer->current());
echo "\n";
