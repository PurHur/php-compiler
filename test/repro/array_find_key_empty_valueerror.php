<?php
declare(strict_types=1);

try {
    array_find_key([], static fn ($v) => true);
    echo "array_find_key: uncaught\n";
    exit(1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

echo "ok\n";
