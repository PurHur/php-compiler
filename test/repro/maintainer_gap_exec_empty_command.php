<?php

declare(strict_types=1);

foreach (['shell_exec', 'exec', 'system', 'passthru'] as $fn) {
    try {
        $fn('');
        fwrite(STDERR, "$fn: expected ValueError\n");
        exit(1);
    } catch (ValueError) {
        echo "$fn: ok\n";
    }
}
