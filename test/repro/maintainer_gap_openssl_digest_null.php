<?php
// #19002 — openssl_digest(null) must TypeError on default profile (ext/openssl/openssl.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_openssl_digest_null.php

try {
    $digest = openssl_digest(null, 'sha256');
    echo 'uncaught: ', $digest, "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
