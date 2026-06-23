<?php

declare(strict_types=1);

foreach (['array_udiff', 'array_udiff_assoc', 'array_diff_ukey'] as $fn) {
    try {
        $fn([1], [2], null);
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
