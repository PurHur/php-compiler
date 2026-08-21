<?php

declare(strict_types=1);

// #33530 leftover of #6295 — AOT must not LogicException on TypeError/argc gates
try {
    openssl_pkey_new('x');
    echo "str-ok\n";
} catch (TypeError $e) {
    echo "str-type\n";
}
try {
    openssl_pkey_new([], 1);
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
