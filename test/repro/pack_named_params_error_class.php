<?php

declare(strict_types=1);

try {
    pack(format: 'N', values: 1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
