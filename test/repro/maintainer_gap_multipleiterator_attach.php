<?php

declare(strict_types=1);

$mi = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
$mi->attachIterator(new ArrayIterator([1, 2]), 'k1');
$mi->attachIterator(new ArrayIterator(['a', 'b']), 'k2');
$mi->rewind();
if (!$mi->valid()) {
    echo "invalid\n";
    exit(1);
}
$cur = $mi->current();
$key = $mi->key();
var_export($cur);
echo "\n";
var_export($key);
echo "\n";
echo "ok\n";
