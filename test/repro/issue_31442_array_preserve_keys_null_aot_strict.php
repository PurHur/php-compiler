<?php
declare(strict_types=1);
/** AOT-friendly strict: array_slice/chunk null preserve_keys (#31442). */
try {
    array_slice([1, 2, 3], 0, 1, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    array_chunk([1, 2], 1, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
