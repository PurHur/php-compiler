<?php

declare(strict_types=1);

/**
 * Maintainer repro: NoRewindIterator suppresses inner rewind (#13170).
 *
 * php-src: ext/spl/spl_iterators.c — NoRewindIterator::rewind() is a no-op.
 */

$inner = new ArrayIterator(['a', 'b']);
$inner->next();
$posBefore = $inner->key();

$wrap = new NoRewindIterator($inner);
$wrap->rewind();

if ($inner->key() !== $posBefore) {
    echo "fail: inner key moved from $posBefore to ".$inner->key()." after NoRewindIterator::rewind()\n";
    exit(1);
}

if (!$wrap->valid()) {
    echo "fail: wrapper invalid after rewind\n";
    exit(1);
}

if ($wrap->current() !== 'b') {
    echo "fail: wrapper current=".$wrap->current()." expected b\n";
    exit(1);
}

echo "ok\n";
