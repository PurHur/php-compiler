<?php

declare(strict_types=1);

/**
 * Issue #13211 — AppendIterator empty rewind (ext/spl/spl_iterators.c).
 */

$app = new AppendIterator();
try {
    $app->rewind();
} catch (Throwable $e) {
    echo 'fail: AppendIterator::rewind() threw '.$e->getMessage()."\n";
    exit(1);
}

if ($app->valid()) {
    echo "fail: empty AppendIterator valid after rewind\n";
    exit(1);
}

echo "ok\n";
