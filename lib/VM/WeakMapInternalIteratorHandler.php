<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\spl\InternalIteratorLiveHandler;

/**
 * InternalIterator backing for WeakMap::getIterator() (php-src Zend/zend_weakrefs.c; #22267).
 *
 * Snapshots live (object|enum) keys at construction — same membership filter as {@see WeakMapIterator}.
 */
final class WeakMapInternalIteratorHandler implements InternalIteratorLiveHandler
{
    /** @var list<array{0: Variable, 1: Variable}> */
    private array $pairs;

    private int $pos = 0;

    public function __construct(ObjectEntry $weakMap)
    {
        $this->pairs = WeakMapIterator::collectLivePairs($weakMap);
    }

    public static function forMap(ObjectEntry $weakMap): self
    {
        return new self($weakMap);
    }

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function next(): void
    {
        ++$this->pos;
    }

    public function valid(): bool
    {
        return $this->pos >= 0 && $this->pos < \count($this->pairs);
    }

    public function current(): Variable
    {
        $value = $this->pairs[$this->pos][1];
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());

        return $copy;
    }

    public function key(): int|string|Variable
    {
        return $this->pairs[$this->pos][0];
    }
}
