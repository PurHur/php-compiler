<?php

declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
$ci = new CachingIterator($it);
try {
    var_export($ci->count());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$ci2 = new CachingIterator(new ArrayIterator([1, 2, 3]), CachingIterator::FULL_CACHE);
foreach ($ci2 as $_) {
}
echo 'full_cache_count=', $ci2->count(), "\n";
