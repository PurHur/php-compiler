<?php

declare(strict_types=1);

try {
    basename('/path', []);
    echo "basename_suffix: uncaught\n";
} catch (Throwable $e) {
    echo 'basename_suffix: ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    dirname('/a/b/c', []);
    echo "dirname_levels: uncaught\n";
} catch (Throwable $e) {
    echo 'dirname_levels: ', $e::class, ': ', $e->getMessage(), "\n";
}
