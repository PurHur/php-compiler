<?php

declare(strict_types=1);

try {
    $chunks = array_chunk(range(1, 5), 2, true);
    echo 'count=', count($chunks), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
