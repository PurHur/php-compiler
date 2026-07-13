<?php

declare(strict_types=1);

try {
    hash_file('nope', __FILE__);
    echo "miss\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

