--TEST--
stdlib is_iterable() / iterator_count() / iterator_apply() (#3313)
--FILE--
<?php
class C implements Iterator {
    private int $i = 0;
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 2; }
    public function current(): int { return $this->i; }
    public function key(): int { return $this->i; }
    public function next(): void { ++$this->i; }
}
$o = new C();
var_export(is_iterable([1, 2]));
echo "\n";
var_export(is_iterable($o));
echo "\n";
echo iterator_count($o), "\n";
echo iterator_apply($o, fn ($v) => $v * 2, []), "\n";
var_export(is_iterable('x'));
echo "\n";
--EXPECT--
true
true
2
2
false
