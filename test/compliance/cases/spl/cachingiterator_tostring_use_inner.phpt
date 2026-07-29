--TEST--
CachingIterator TOSTRING_USE_INNER stringifies inner iterator (#24912)
--FILE--
<?php
class CachingIteratorToStringUseInnerStr implements Iterator {
    private int $i = 0;
    /** @var list<int> */
    private array $d = [1];
    public function __toString(): string { return 'INNER'; }
    public function current(): mixed { return $this->d[$this->i] ?? null; }
    public function key(): mixed { return $this->i; }
    public function next(): void { $this->i++; }
    public function rewind(): void { $this->i = 0; }
    public function valid(): bool { return isset($this->d[$this->i]); }
}
$it = new CachingIterator(new CachingIteratorToStringUseInnerStr(), CachingIterator::TOSTRING_USE_INNER);
$it->rewind();
echo 'ok=', (string) $it, "\n";
foreach ($it as $k => $v) {}
echo 'after=', var_export((string) $it, true), "\n";
try {
    $bad = new CachingIterator(new ArrayIterator([9]), CachingIterator::TOSTRING_USE_INNER);
    $bad->rewind();
    echo 'bad=', (string) $bad, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
ok=INNER
after=''
Error:Object of class ArrayIterator could not be converted to string
