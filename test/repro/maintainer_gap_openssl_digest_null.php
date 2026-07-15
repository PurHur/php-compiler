<?php
// #19039 — openssl_digest(null) coerces to '' and returns empty-string SHA256 (ext/openssl/openssl.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_openssl_digest_null.php

$digest = openssl_digest(null, 'sha256');
$expected = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
if ($digest !== $expected) {
    fwrite(STDERR, "fail: expected {$expected}, got {$digest}\n");
    exit(1);
}

echo "ok\n";
