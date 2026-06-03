<?php

declare(strict_types=1);

try {
    file_exists([]);
    echo "no throw\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
