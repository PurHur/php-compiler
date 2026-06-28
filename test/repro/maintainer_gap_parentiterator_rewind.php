<?php

declare(strict_types=1);

/**
 * Issue #13211 — ParentIterator rewind (ext/spl/spl_iterators.c).
 */

$p = new ParentIterator(new RecursiveArrayIterator([1, [2, 3]]));
try {
    $p->rewind();
} catch (Throwable $e) {
    echo 'fail: ParentIterator::rewind() threw '.$e->getMessage()."\n";
    exit(1);
}

if (!$p->valid()) {
    echo "fail: ParentIterator valid after rewind\n";
    exit(1);
}

echo "ok\n";
