<?php

declare(strict_types=1);

// #33499 leftover of #20240 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_pkey_get_public(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_pkey_get_public();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
