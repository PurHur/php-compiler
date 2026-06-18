<?php
declare(strict_types=1);

foreach (['array_shift', 'array_pop', 'array_unshift'] as $fn) {
    try {
        if ($fn === 'array_unshift') {
            $fn([1, 2], 0);
        } else {
            $fn([1, 2]);
        }
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
