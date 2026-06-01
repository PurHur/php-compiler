--TEST--
foreach by-reference over Iterator throws Error (Zend parity, #4080)
--FILE--
<?php
class C implements Iterator {
    private array $a = [1, 2];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
$c = new C();
try {
    foreach ($c as &$v) {
        $v = 99;
    }
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
An iterator cannot be used with foreach by reference
