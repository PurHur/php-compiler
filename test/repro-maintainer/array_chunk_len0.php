<?php
try {
    array_chunk([1, 2, 3], 0);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
