<?php

declare(strict_types=1);

foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn('abc', null);
        fwrite(STDERR, "$fn: expected TypeError on null needle\n");
        exit(1);
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}
