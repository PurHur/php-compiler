<?php

declare(strict_types=1);

/**
 * Maintainer repro: InfiniteIterator wraps inner and rewinds at end (#13170).
 *
 * php-src: ext/spl/spl_iterators.c — spl_infinite_iterator_move_forward.
 */

$inner = new ArrayIterator([1, 2]);
$inf = new InfiniteIterator($inner);
$inf->rewind();
$vals = [];
for ($i = 0; $i < 5; ++$i) {
    if (!$inf->valid()) {
        echo "fail: invalid at i=$i\n";
        exit(1);
    }
    $vals[] = $inf->current();
    $inf->next();
}
$expected = [1, 2, 1, 2, 1];
if ($vals !== $expected) {
    echo 'fail: got '.json_encode($vals).' expected '.json_encode($expected)."\n";
    exit(1);
}

echo "ok\n";
