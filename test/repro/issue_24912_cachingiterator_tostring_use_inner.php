<?php
/**
 * CachingIterator::TOSTRING_USE_INNER stringifies inner, not current (#24912).
 *
 *   php test/repro/issue_24912_cachingiterator_tostring_use_inner.php
 *   php bin/vm.php test/repro/issue_24912_cachingiterator_tostring_use_inner.php
 */
class Issue24912InnerStr implements Iterator
{
    private int $i = 0;

    /** @var list<int> */
    private array $d = [1];

    public function __toString(): string
    {
        return 'INNER';
    }

    public function current(): mixed
    {
        return $this->d[$this->i] ?? null;
    }

    public function key(): mixed
    {
        return $this->i;
    }

    public function next(): void
    {
        $this->i++;
    }

    public function rewind(): void
    {
        $this->i = 0;
    }

    public function valid(): bool
    {
        return isset($this->d[$this->i]);
    }
}

$it = new CachingIterator(new Issue24912InnerStr(), CachingIterator::TOSTRING_USE_INNER);
$it->rewind();
echo 'ok=', var_export((string) $it, true), "\n";
foreach ($it as $k => $v) {
}
echo 'after=', var_export((string) $it, true), "\n";

try {
    $bad = new CachingIterator(new ArrayIterator([9]), CachingIterator::TOSTRING_USE_INNER);
    $bad->rewind();
    echo 'bad=', var_export((string) $bad, true), "\n";
} catch (Throwable $e) {
    echo 'bad_THROW=', get_class($e), '|', $e->getMessage(), "\n";
}
