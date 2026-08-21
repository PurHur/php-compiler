<?php

declare(strict_types=1);

// #33514 leftover of #6421 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_csr_get_public_key(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_csr_get_public_key();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
