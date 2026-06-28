<?php

declare(strict_types=1);

/**
 * Issue #13173 — AppendIterator::append() (ext/spl/spl_iterators.c).
 */

$app = new AppendIterator();
$app->append(new ArrayIterator([1, 2]));
$app->rewind();

if (!$app->valid()) {
    echo "fail: not valid after rewind\n";
    exit(1);
}
if ($app->current() !== 1) {
    echo 'fail: current='.var_export($app->current(), true)."\n";
    exit(1);
}

echo "ok\n";
