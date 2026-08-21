<?php

declare(strict_types=1);

// #33527 leftover of #6421 — AOT must not LogicException on TypeError/argc gates
$k = null;
try {
    openssl_csr_new(null, $k);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_csr_new();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
