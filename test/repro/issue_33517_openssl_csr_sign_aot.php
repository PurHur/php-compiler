<?php

declare(strict_types=1);

// #33517 leftover of #6421 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_csr_sign(null, null, null, 365);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_csr_sign();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
