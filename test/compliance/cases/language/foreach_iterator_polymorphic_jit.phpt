--TEST--
foreach over Iterator on polymorphic object parameter (JIT, #4083)
--FILE--
<?php
class Impl implements Iterator {
    private array $a = [1, 2];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
class Other implements Iterator {
    private array $a = [4];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
function run(object $o): void {
    $n = 0;
    foreach ($o as $v) {
        $n += $v;
    }
    echo $n, "\n";
}
run(new Impl());
--EXPECT--
3
