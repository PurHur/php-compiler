<?php
/** iterator_to_array(..., null) $preserve_keys under strict_types (#31340) */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(iterator_to_array(new ArrayIterator([1, 2]), null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
