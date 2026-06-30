<?php

declare(strict_types=1);

try {
    array_pad([1, 2], 2.9, 0);
    fwrite(STDERR, "uncaught\n");
    exit(1);
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
