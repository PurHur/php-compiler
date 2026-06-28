<?php

declare(strict_types=1);

/**
 * Issue #13178 — FilterIterator user subclass wrapper state (ext/spl/spl_iterators.c).
 */

class EvenFilter extends FilterIterator
{
    public function __construct(ArrayIterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        return 0 === ($this->current() % 2);
    }
}

class EvenRecursiveFilter extends RecursiveFilterIterator
{
    public function __construct(RecursiveIterator $iterator)
    {
        parent::__construct($iterator);
    }

    public function accept(): bool
    {
        return 0 === ($this->current() % 2);
    }
}

$filter = new EvenFilter(new ArrayIterator([1, 2, 3, 4, 5]));
$filter->rewind();
if (!$filter->valid()) {
    echo "fail: valid after rewind\n";
    exit(1);
}
$seen = [];
foreach ($filter as $value) {
    $seen[] = $value;
}
if ($seen !== [2, 4]) {
    echo 'fail: FilterIterator foreach got '.json_encode($seen)."\n";
    exit(1);
}

$recursive = new EvenRecursiveFilter(new RecursiveArrayIterator([1, 2, 3, 4]));
$recursive->rewind();
if (!$recursive->valid()) {
    echo "fail: RecursiveFilterIterator valid after rewind\n";
    exit(1);
}
$seenRecursive = [];
foreach ($recursive as $value) {
    $seenRecursive[] = $value;
}
if ($seenRecursive !== [2, 4]) {
    echo 'fail: RecursiveFilterIterator foreach got '.json_encode($seenRecursive)."\n";
    exit(1);
}

echo "ok\n";
