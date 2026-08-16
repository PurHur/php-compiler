<?php
// MultipleIterator::attachIterator duplicate assoc info → InvalidArgumentException (php-src spl_iterators.c).
error_reporting(E_ALL);
try {
    $m = new MultipleIterator(MultipleIterator::MIT_NEED_ALL | MultipleIterator::MIT_KEYS_ASSOC);
    $m->attachIterator(new ArrayIterator(['a' => 1]), 'k');
    $m->attachIterator(new ArrayIterator(['a' => 2]), 'k');
    echo "attached\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
