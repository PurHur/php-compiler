<?php

declare(strict_types=1);

// Zend parity repro (#5984): extra args must be ArgumentCountError (uncaught exits non-zero).
foreach (['getmypid', 'getmyinode', 'sys_get_temp_dir'] as $fn) {
    try {
        $fn('extra');
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), PHP_EOL;
    }
}
