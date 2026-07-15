<?php
/**
 * Maintainer repro for #6421 — openssl CSR lifecycle missing on VM.
 */
$funcs = [
    'openssl_csr_new',
    'openssl_csr_export',
    'openssl_csr_export_to_file',
    'openssl_csr_sign',
    'openssl_csr_get_subject',
    'openssl_csr_get_public_key',
];
foreach ($funcs as $f) {
    echo $f, ': ', function_exists($f) ? "yes\n" : "no\n";
}
