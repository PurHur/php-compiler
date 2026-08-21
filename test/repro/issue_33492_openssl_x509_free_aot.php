<?php

declare(strict_types=1);

// #33492 leftover of #20272 — deprecated typed noop; TypeError/argc without openssl_x509_read JIT.
try {
    openssl_x509_free(null);
    echo "null-type=ok\n";
} catch (Throwable $e) {
    echo 'null-type=', $e::class, "\n";
}
try {
    openssl_x509_free();
    echo "argc=ok\n";
} catch (Throwable $e) {
    echo 'argc=', $e::class, "\n";
}
