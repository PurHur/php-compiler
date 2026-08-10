<?php

declare(strict_types=1);

/**
 * #29890 — hash_hmac_file(null) under strict_types → TypeError ($algo string).
 * php-src: ext/hash/hash.c / hash.stub.php
 */
try {
    hash_hmac_file(null, '/etc/hosts', 'k');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
