<?php
declare(strict_types=1);
// Maintainer gap probe: stream_filter_append/prepend($stream, null) under strict_types (#31408).
// Zend: TypeError Argument #2 ($filter_name) must be of type string, null given
$h = fopen('php://memory', 'r+');
try {
    stream_filter_append($h, null);
    echo "NO_THROW_APPEND\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    stream_filter_prepend($h, null);
    echo "NO_THROW_PREPEND\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
fclose($h);
