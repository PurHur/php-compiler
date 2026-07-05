<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
try {
    stream_set_chunk_size($fp, null);
    echo "fail: no exception\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
