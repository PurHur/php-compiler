--TEST--
Language: foreach by-ref over ArrayIterator/ArrayObject mutates storage (#19444, Zend/zend_execute.c)
--FILE--
<?php
$it = new ArrayIterator([1, 2]);
foreach ($it as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', iterator_to_array($it)), "\n";

$o = new ArrayObject([1, 2]);
foreach ($o as &$v) {
    $v *= 10;
}
unset($v);
echo $o[0], ',', $o[1], "\n";

$r = new RecursiveArrayIterator([3, 4]);
foreach ($r as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', iterator_to_array($r)), "\n";

class C implements Iterator {
    private array $a = [1];
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < count($this->a); }
    public function current(): mixed { return $this->a[$this->i]; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
}
try {
    foreach (new C() as &$v) {
        $v = 9;
    }
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
10,20
10,20
30,40
An iterator cannot be used with foreach by reference
