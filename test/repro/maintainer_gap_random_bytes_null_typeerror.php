<?php

declare(strict_types=1);

foreach (['random_bytes', 'openssl_random_pseudo_bytes'] as $fn) {
    try {
        $fn(null);
        echo "$fn: ok\n";
    } catch (Throwable $e) {
        echo "$fn: ", get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
