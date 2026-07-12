<?php

declare(strict_types=1);

try {
    openssl_random_pseudo_bytes(-1, $strong);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
