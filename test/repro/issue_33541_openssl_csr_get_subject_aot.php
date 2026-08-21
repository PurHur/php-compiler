<?php

declare(strict_types=1);

// #33541 leftover of #32692 — AOT must not LogicException/compile-fatal on TypeError/argc gates
// TypeError probe uses null (peer #33514): array literals are often TYPE_VALUE, not HASHTABLE.
try {
    openssl_csr_get_subject(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_csr_get_subject();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
