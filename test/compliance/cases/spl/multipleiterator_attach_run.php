<?php

declare(strict_types=1);

$mi = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
$mi->attachIterator(new ArrayIterator(['a' => 1]), 'k1');
$mi->attachIterator(new ArrayIterator(['b' => 2]), 'k2');
$mi->rewind();

if (!$mi->valid()) {
    echo "fail: not valid after rewind\n";
    exit(1);
}

$current = $mi->current();
$key = $mi->key();
if ($current !== ['k1' => 1, 'k2' => 2]) {
    echo 'fail: current='.var_export($current, true)."\n";
    exit(1);
}
if ($key !== ['k1' => 'a', 'k2' => 'b']) {
    echo 'fail: key='.var_export($key, true)."\n";
    exit(1);
}
if ($mi->countIterators() !== 2) {
    echo "fail: countIterators\n";
    exit(1);
}

echo "ok\n";
