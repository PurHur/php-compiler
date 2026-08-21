<?php

declare(strict_types=1);

// #33496 leftover of #20240 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_pkey_get_details(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_pkey_get_details();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
