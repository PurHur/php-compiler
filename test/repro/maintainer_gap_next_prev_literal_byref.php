<?php

declare(strict_types=1);

try {
    next([1, 2, 3]);
    echo "next: no throw\n";
} catch (Throwable $e) {
    echo 'next: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    prev([1, 2, 3]);
    echo "prev: no throw\n";
} catch (Throwable $e) {
    echo 'prev: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [1, 2, 3];
echo 'var next: ', next($a), ' key: ', key($a), "\n";
