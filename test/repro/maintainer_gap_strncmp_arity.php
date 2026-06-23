<?php

declare(strict_types=1);

foreach (['strncmp', 'strncasecmp'] as $fn) {
    try {
        $fn('a', 'b');
        fwrite(STDERR, "$fn: expected ArgumentCountError\n");
        exit(1);
    } catch (ArgumentCountError $e) {
        echo "$fn: ok\n";
    }
}
