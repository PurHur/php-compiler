--TEST--
Language: Countable/ArrayAccess/Iterator typed returns without strict_types coerce (#26433)
--FILE--
<?php
class C implements Countable {
    public function count(): int { return "3"; }
}
echo count(new C()), "\n";

class A implements ArrayAccess {
    public function offsetExists($o): bool { return 1; }
    public function offsetGet($o): mixed { return null; }
    public function offsetSet($o, $v): void {}
    public function offsetUnset($o): void {}
}
var_export(isset((new A)[0]));
echo "\n";

class I implements Iterator {
    private $i = 0;
    public function current(): mixed { return 'x'; }
    public function key(): mixed { return 0; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 1 ? 1 : false; }
}
foreach (new I as $x) {
    echo $x, "\n";
    break;
}
--EXPECT--
3
true
x
