<?php

declare(strict_types=1);

foreach (['strspn', 'strcspn'] as $fn) {
    try {
        $fn(null, 'abc');
        fwrite(STDERR, "$fn: expected TypeError on null haystack\n");
        exit(1);
    } catch (TypeError) {
        echo "$fn: ok\n";
    }
}
