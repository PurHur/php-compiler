<?php

declare(strict_types=1);

// #33510 leftover of #6295 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_pkey_get_private(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_pkey_get_private();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
