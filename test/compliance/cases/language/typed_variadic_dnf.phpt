--TEST--
typed variadic DNF parameters — intersection and union per-element checks (#6819)
--FILE--
<?php
interface ICountable { public function count(): int; }
interface IIterator { public function current(): mixed; public function next(): void; public function key(): mixed; public function valid(): bool; public function rewind(): void; }
class C implements ICountable, IIterator {
    private array $a = [];
    public function count(): int { return count($this->a); }
    public function current(): mixed { return current($this->a); }
    public function next(): void { next($this->a); }
    public function key(): mixed { return key($this->a); }
    public function valid(): bool { return key($this->a) !== null; }
    public function rewind(): void { reset($this->a); }
}
function takeIntersection(ICountable&IIterator ...$xs): int {
    return count($xs);
}
function takeDnf((ICountable&IIterator)|string ...$xs): int {
    return count($xs);
}
echo takeIntersection(new C()), "\n";
echo takeDnf(new C(), 'ok'), "\n";
try {
    takeIntersection('bad');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    takeDnf(42);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
2
Argument must be of type ICountable&IIterator, string given
Argument must be of type (ICountable&IIterator)|string, int given
