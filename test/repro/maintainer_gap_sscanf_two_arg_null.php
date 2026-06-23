<?php

declare(strict_types=1);

try {
    sscanf(null, '%d');
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
