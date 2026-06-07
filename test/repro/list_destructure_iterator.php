<?php

class I implements Iterator
{
    private array $a = [0 => 'a', 1 => 'b'];
    private int $i = 0;

    public function rewind(): void
    {
        $this->i = 0;
    }

    public function valid(): bool
    {
        return isset($this->a[$this->i]);
    }

    public function current(): mixed
    {
        return $this->a[$this->i];
    }

    public function key(): mixed
    {
        return $this->i;
    }

    public function next(): void
    {
        ++$this->i;
    }
}

[$x, $y] = new I();
echo "$x,$y\n";

list($a, $b) = new I();
echo "$a,$b\n";
