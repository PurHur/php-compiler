--TEST--
call argument spread accepts Generator and Traversable operands (Zend VM parity, #4452, #24646)
--FILE--
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$gen = (function (): Generator {
    yield 1;
    yield 2;
    yield 3;
})();
echo sum(...$gen), "\n";

class GenAgg implements IteratorAggregate {
    public function getIterator(): Generator {
        yield 4;
        yield 5;
        yield 6;
    }
}
function sum2(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
echo sum2(...(new GenAgg())), "\n";

class Three implements Iterator {
    private int $i = 0;
    public function current(): int { return $this->i + 1; }
    public function key(): int { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return $this->i < 3; }
}
function sum3(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
echo sum3(...(new Three())), "\n";

// Fresh Generator call form (issue #24646) — must not drop the first yield.
function g(): Generator { yield 1; yield 2; yield 3; }
echo sum(...g()), "\n";

class PairAgg implements IteratorAggregate {
    public function getIterator(): Generator {
        yield 1;
        yield 2;
    }
}
function pairSum(int $a, int $b): int {
    return $a + $b;
}
echo pairSum(...(new PairAgg())), "\n";

// Named keys from Generator → named args (must not lose the first key).
function namedPair(int $a, int $b): void {
    echo "$a,$b\n";
}
$named = (function (): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
})();
namedPair(...$named);

echo sum(...[1, 2, 3]), "\n";
echo sum(...new ArrayIterator([1, 2, 3])), "\n";
--EXPECT--
6
15
6
6
3
1,2
6
6
