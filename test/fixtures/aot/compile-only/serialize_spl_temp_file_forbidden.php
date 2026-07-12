<?php

declare(strict_types=1);

try {
    serialize(new SplTempFileObject());
    echo "SplTempFileObject:no-throw\n";
} catch (Throwable $e) {
    echo 'SplTempFileObject:', get_class($e), ':', $e->getMessage(), "\n";
}
