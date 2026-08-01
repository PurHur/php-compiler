--TEST--
Language: Countable/ArrayAccess/Iterator typed returns under strict_types — Zend TypeError (#26433)
--FILE--
<?php
declare(strict_types=1);
class C implements Countable {
    public function count(): int { return "3"; }
}
try {
    count(new C());
    echo "count: ok\n";
} catch (Throwable $e) {
    echo "count: ", get_class($e), ": ", $e->getMessage(), "\n";
}

class A implements ArrayAccess {
    public function offsetExists($o): bool { return 1; }
    public function offsetGet($o): mixed { return null; }
    public function offsetSet($o, $v): void {}
    public function offsetUnset($o): void {}
}
try {
    var_export(isset((new A)[0]));
    echo "\n";
} catch (Throwable $e) {
    echo "isset: ", get_class($e), ": ", $e->getMessage(), "\n";
}

class I implements Iterator {
    private $i = 0;
    public function current(): mixed { return 1; }
    public function key(): mixed { return 0; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 1 ? 1 : false; }
}
try {
    foreach (new I as $x) {
        echo "foreach: $x\n";
        break;
    }
} catch (Throwable $e) {
    echo "foreach: ", get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECT--
count: TypeError: C::count(): Return value must be of type int, string returned
isset: TypeError: A::offsetExists(): Return value must be of type bool, int returned
foreach: TypeError: I::valid(): Return value must be of type bool, int returned
