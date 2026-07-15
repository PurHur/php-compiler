<?php

declare(strict_types=1);

try {
    random_bytes(null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
