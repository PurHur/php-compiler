<?php

declare(strict_types=1);

// Repro for #13123 — CachingIterator::hasNext() after next() on one-item inner iterator.

$it = new CachingIterator(new ArrayIterator([1]));
$it->next();
if (!$it->valid()) {
    echo "fail: valid() false after next()\n";
    exit(1);
}
try {
    $hasNext = $it->hasNext();
} catch (LogicException $e) {
    echo 'fail: hasNext() LogicException: ', $e->getMessage(), "\n";
    exit(1);
}
if ($hasNext) {
    echo "fail: hasNext() true expected false\n";
    exit(1);
}

echo "ok\n";
