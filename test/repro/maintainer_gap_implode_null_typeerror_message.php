<?php

declare(strict_types=1);

foreach (['implode', 'join'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, $fn."(null) should have thrown\n");
        exit(1);
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
