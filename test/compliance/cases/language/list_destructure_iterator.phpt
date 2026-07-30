--TEST--
Language: list destructuring from Iterator raises object-as-array Error (#25096, re-#7452, zend_vm_def.h)
--FILE--
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

try {
    [$x, $y] = new I();
    echo "$x,$y\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    list($a, $b) = new I();
    echo "$a,$b\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Error:Cannot use object of type I as array
Error:Cannot use object of type I as array
