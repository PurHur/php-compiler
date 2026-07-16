<?php
error_reporting(E_ALL);
try {
    $it = new CachingIterator(new ArrayIterator(['a', 'b']), CachingIterator::FULL_CACHE);
    foreach ($it as $v) { echo "v=$v\n"; }
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
