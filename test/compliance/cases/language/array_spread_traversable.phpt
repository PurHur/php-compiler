--TEST--
language: array literal spread accepts Traversable operands (Zend zend_compile.c parity, #4453)
--FILE--
<?php
class C implements IteratorAggregate {
    public function getIterator(): Traversable {
        yield 'a' => 1;
        yield 'b' => 2;
    }
}
var_export([...new C()]);
echo "\n";

$gen = (function (): Generator {
    yield 0 => 10;
    yield 1 => 20;
})();
var_export([...$gen]);
echo "\n";

class Three implements Iterator {
    private int $i = 0;
    public function current(): int { return $this->i + 1; }
    public function key(): int { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
var_export([...(new Three())]);
echo "\n";

try {
    var_export([...null]);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  0 => 10,
  1 => 20,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
TypeError
