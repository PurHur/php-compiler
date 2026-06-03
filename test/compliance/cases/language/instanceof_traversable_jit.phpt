--TEST--
instanceof Traversable — JIT execute parity (#4754)
--FILE--
<?php
class I implements Iterator {
    private array $a = [1];
    public function current(): mixed { return current($this->a); }
    public function next(): void { next($this->a); }
    public function key(): mixed { return key($this->a); }
    public function valid(): bool { return key($this->a) !== null; }
    public function rewind(): void { reset($this->a); }
}
var_export(new I() instanceof Traversable);
echo "\n";
?>
--EXPECT--
true

--JIT--
