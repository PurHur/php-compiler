<?php
declare(strict_types=1);

try {
    $hash = password_hash('secret', null);
    echo 'prefix=', substr($hash, 0, 4), "\n";
    echo 'len=', strlen($hash), "\n";
    echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
