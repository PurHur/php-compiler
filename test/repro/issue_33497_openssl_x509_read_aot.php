<?php

declare(strict_types=1);

// #33497 leftover of #7268 — TypeError/argc gate; happy-path PEM→OpenSSLCertificate still VM-only.
try {
    openssl_x509_read(null);
    echo "null-type=ok\n";
} catch (Throwable $e) {
    echo 'null-type=', $e::class, "\n";
}
try {
    openssl_x509_read();
    echo "argc=ok\n";
} catch (Throwable $e) {
    echo 'argc=', $e::class, "\n";
}
