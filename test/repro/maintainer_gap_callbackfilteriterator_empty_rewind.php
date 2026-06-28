<?php

declare(strict_types=1);

/**
 * Issue #13211 — CallbackFilterIterator empty rewind (ext/spl/spl_iterators.c).
 */

$cb = new CallbackFilterIterator(new ArrayIterator([]), static fn ($v) => true);
try {
    $cb->rewind();
} catch (Throwable $e) {
    echo 'fail: CallbackFilterIterator::rewind() threw '.$e->getMessage()."\n";
    exit(1);
}

if ($cb->valid()) {
    echo "fail: CallbackFilterIterator valid on empty iterator\n";
    exit(1);
}

echo "ok\n";
