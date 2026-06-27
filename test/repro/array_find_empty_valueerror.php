<?php
declare(strict_types=1);

try {
    array_find([], static fn ($v) => true);
    echo "array_find: uncaught\n";
    exit(1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

echo "ok\n";
