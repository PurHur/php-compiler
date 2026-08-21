<?php

declare(strict_types=1);

// #33503 leftover of #20240 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_get_publickey(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_get_publickey();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
