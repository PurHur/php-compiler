<?php

declare(strict_types=1);

// #33507 leftover of #20306 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_get_privatekey(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_get_privatekey();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
