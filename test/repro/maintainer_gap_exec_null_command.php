<?php

declare(strict_types=1);

foreach (['exec', 'shell_exec', 'system', 'passthru', 'popen'] as $fn) {
    try {
        if ('popen' === $fn) {
            $fn(null, 'r');
        } else {
            $fn(null);
        }
        fwrite(STDERR, "$fn(null): expected TypeError\n");
        exit(1);
    } catch (TypeError) {
        echo "$fn(null): ok\n";
    }
}
