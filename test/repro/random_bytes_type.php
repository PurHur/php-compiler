<?php
declare(strict_types=1);

echo strlen(random_bytes(8)), "\n";

try {
    echo strlen(random_bytes('16')), "\n";
} catch (Throwable $e) {
    echo 'numeric-string:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    random_bytes([]);
} catch (Throwable $e) {
    echo 'array:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    random_bytes(0);
} catch (Throwable $e) {
    echo 'zero:', get_class($e), ':', $e->getMessage(), "\n";
}
