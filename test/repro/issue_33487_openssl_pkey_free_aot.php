<?php

declare(strict_types=1);

// #33487 leftover of #20271 — AOT must not LogicException on typed noop paths
try {
    openssl_pkey_free(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_pkey_free();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
