<?php

declare(strict_types=1);

foreach (['str_starts_with', 'str_ends_with', 'str_contains'] as $fn) {
    try {
        $fn(null, 'a');
        fwrite(STDERR, "$fn: expected TypeError on null haystack\n");
        exit(1);
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}
