<?php

declare(strict_types=1);

// #33489 leftover of #20272 — AOT must not LogicException on typed noop paths
try {
    openssl_x509_free(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_x509_free();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
