<?php

declare(strict_types=1);

try {
    var_dump(hash_hmac('nope', 'data', 'key'));
    echo "uncaught\n";
} catch (Throwable $e) {
    echo 'threw:', get_class($e), ': ', $e->getMessage(), "\n";
}
