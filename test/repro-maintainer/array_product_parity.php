<?php

declare(strict_types=1);

echo array_product([]), "\n";

try {
    array_product([1, 'x']);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
