<?php

declare(strict_types=1);

try {
    get_parent_class('x');
    echo "no exception\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'string given')) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
