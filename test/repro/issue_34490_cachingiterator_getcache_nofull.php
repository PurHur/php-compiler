<?php

declare(strict_types=1);

// AOT must throw when FULL_CACHE is unset (#34490 / php-src zim_CachingIterator_getCache).
$it = new CachingIterator(new ArrayIterator([1, 2]));
foreach ($it as $v) {
    echo $v;
}
try {
    $it->getCache();
    echo 'NO_THROW';
} catch (BadMethodCallException $e) {
    echo 'ex:', $e->getMessage();
}
echo "\n";
