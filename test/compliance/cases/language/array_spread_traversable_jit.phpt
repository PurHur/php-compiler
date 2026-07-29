--TEST--
language: array literal spread Traversable operands JIT execute (#4686, #4453, #24645)
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

// Generator opened by rewind must not drop the first yield (#24645).
var_export([...(function () { yield 1; yield 2; yield 3; })()]);
echo "\n";

// Nested ctor Array_ must not steal ARG_SEND from the spread result (#24645).
var_export([...new ArrayIterator([1, 2, 3])]);
echo "\n";
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
array (
  0 => 1,
  1 => 2,
  2 => 3,
)
array (
  0 => 1,
  1 => 2,
  2 => 3,
)

